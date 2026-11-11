#!/usr/bin/env node
/**
 * Standalone Node.js Baileys WhatsApp HTTP Gateway for NMS OLT ZTE
 * Compatible with Windows & Ubuntu Linux (systemd / PM2).
 *
 * Install dependencies once before running:
 *   npm install @whiskeysockets/baileys pino qrcode-terminal
 *
 * Run:
 *   WA_PORT=3001 WA_API_KEY=rahasia node scripts/baileys-gateway.mjs
 */

import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PORT = Number(process.env.WA_PORT || 3001);
const HOST = process.env.WA_HOST || '127.0.0.1';
const API_KEY = (process.env.WA_API_KEY || '').trim();
const AUTH_DIR = process.env.WA_AUTH_DIR || path.resolve(__dirname, '../storage/app/private/baileys-auth');

fs.mkdirSync(AUTH_DIR, { recursive: true });

let makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, pino, qrcode;
try {
    const baileys = await import('@whiskeysockets/baileys');
    makeWASocket = baileys.default?.default || baileys.default || baileys.makeWASocket;
    useMultiFileAuthState = baileys.useMultiFileAuthState;
    DisconnectReason = baileys.DisconnectReason;
    fetchLatestBaileysVersion = baileys.fetchLatestBaileysVersion;
    pino = (await import('pino')).default;
    qrcode = (await import('qrcode-terminal')).default;
} catch {
    console.error('[Baileys Gateway] Paket @whiskeysockets/baileys belum terpasang.');
    console.error('Jalankan perintah berikut di terminal (Windows / Ubuntu):');
    console.error('  npm install @whiskeysockets/baileys pino qrcode-terminal');
    process.exit(1);
}

let sock = null;
let connectionState = 'connecting';
let lastQr = null;

async function startWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }));

    sock = makeWASocket({
        version,
        auth: state,
        printQRInTerminal: false,
        logger: pino({ level: 'silent' }),
        browser: ['NMS OLT ZTE', 'Ubuntu', '1.0.0'],
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;
        if (qr) {
            lastQr = qr;
            console.log('\n[Baileys Gateway] Scan QR Code berikut menggunakan WhatsApp (Perangkat Tertaut):');
            qrcode.generate(qr, { small: true });
        }
        if (connection === 'open') {
            connectionState = 'connected';
            lastQr = null;
            console.log('[Baileys Gateway] WhatsApp Terhubung:', sock.user?.id || 'OK');
        } else if (connection === 'close') {
            connectionState = 'disconnected';
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
            console.warn('[Baileys Gateway] Koneksi terputus (code:', statusCode, '). Reconnect:', shouldReconnect);
            if (shouldReconnect) {
                setTimeout(startWhatsApp, 4000);
            }
        }
    });
}

function normalizeJid(raw) {
    const value = String(raw || '').trim();
    if (!value) return '';
    if (value.includes('@')) return value;
    let digits = value.replace(/[^0-9]/g, '');
    if (digits.startsWith('08')) digits = '62' + digits.slice(1);
    else if (digits.startsWith('8')) digits = '62' + digits;
    return `${digits}@s.whatsapp.net`;
}

function authorized(req) {
    if (!API_KEY) return true;
    const authHeader = String(req.headers['authorization'] || '').replace(/^Bearer\s+/i, '').trim();
    const apiKeyHeader = String(req.headers['x-api-key'] || req.headers['apikey'] || '').trim();
    return authHeader === API_KEY || apiKeyHeader === API_KEY;
}

function readJson(req) {
    return new Promise((resolve, reject) => {
        let body = '';
        req.on('data', (chunk) => {
            body += chunk;
            if (body.length > 1024 * 256) reject(new Error('Payload terlalu besar'));
        });
        req.on('end', () => {
            try {
                resolve(body ? JSON.parse(body) : {});
            } catch {
                reject(new Error('Format JSON tidak valid'));
            }
        });
        req.on('error', reject);
    });
}

function respond(res, status, payload) {
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(payload));
}

const server = http.createServer(async (req, res) => {
    const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);

    if (!authorized(req)) {
        return respond(res, 401, { status: false, message: 'API Key WhatsApp Gateway tidak cocok.' });
    }

    if (req.method === 'GET' && url.pathname === '/status') {
        return respond(res, 200, {
            status: connectionState === 'connected',
            connection: connectionState,
            user: sock?.user || null,
            has_qr: Boolean(lastQr),
        });
    }

    if (req.method === 'GET' && url.pathname === '/groups') {
        if (!sock || connectionState !== 'connected') {
            return respond(res, 503, { status: false, message: 'WhatsApp belum terhubung.' });
        }
        try {
            const groups = await sock.groupFetchAllParticipating();
            const list = Object.values(groups).map((g) => ({ jid: g.id, subject: g.subject, size: g.participants?.length || 0 }));
            return respond(res, 200, { status: true, groups: list });
        } catch (e) {
            return respond(res, 500, { status: false, message: e.message });
        }
    }

    if (req.method === 'POST' && ['/send-message', '/send', '/api/send'].includes(url.pathname)) {
        if (!sock || connectionState !== 'connected') {
            return respond(res, 503, { status: false, message: 'WhatsApp Baileys belum terhubung (state: ' + connectionState + ').' });
        }
        try {
            const data = await readJson(req);
            const target = data.jid || data.chatId || data.number || data.phone || data.to || data.target;
            const text = data.message || data.text || data.body;
            const jid = normalizeJid(target);

            if (!jid || !text) {
                return respond(res, 422, { status: false, message: 'Field number/jid dan message/text wajib diisi.' });
            }

            await sock.sendMessage(jid, { text: String(text) });
            return respond(res, 200, { status: true, message: 'Pesan WhatsApp terkirim.', jid });
        } catch (e) {
            return respond(res, 500, { status: false, message: e.message || 'Gagal mengirim pesan WhatsApp.' });
        }
    }

    return respond(res, 404, { status: false, message: 'Endpoint tidak ditemukan.' });
});

server.listen(PORT, HOST, () => {
    console.log(`[Baileys Gateway] HTTP API aktif di http://${HOST}:${PORT}`);
    console.log(`[Baileys Gateway] Endpoint kirim pesan: POST http://${HOST}:${PORT}/send-message`);
    console.log(`[Baileys Gateway] Endpoint cek grup WA: GET  http://${HOST}:${PORT}/groups`);
    startWhatsApp();
});
