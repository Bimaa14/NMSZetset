<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class OnuToolRequest extends FormRequest {
    public function authorize():bool{return $this->user()?->allows('operate')===true;}
    public function rules():array{
        $kind=$this->input('kind');$delete=$this->input('action')==='delete';
        $token=['string','max:64','regex:/^[A-Za-z0-9_.@()+-]+$/'];
        $rules=['kind'=>'required|in:tcont,gem,service,ports,onu_service,wan,tr069,remote','action'=>'required|in:create,update,delete'];
        if($kind==='onu_service')$rules['name']=['required',...$token];
        elseif($kind==='ports'){$rules['port_type']='required|in:ethernet,wlan';$rules['port_id']='required|integer|min:1|max:4';}
        else $rules['id']='required|integer|min:1|max:'.match($kind){'tcont'=>8,'gem'=>128,'service'=>128,'remote'=>999,default=>255};
        if($delete)return $rules;
        $extra=match($kind){
            'tcont'=>['name'=>['nullable',...$token],'profile'=>['required',...$token]],
            'gem'=>['name'=>['nullable',...$token],'tcont_id'=>'required|integer|min:1|max:8','profile'=>['nullable',...$token],'traffic_type'=>'required|in:ethernet,multicast'],
            'service'=>['vlan'=>'required|integer|min:1|max:4094','gem_id'=>'required|integer|min:1|max:128','description'=>'nullable|string|max:80|regex:/^[A-Za-z0-9_ .(),:\/-]+$/'],
            'ports'=>['mode'=>'required|in:tag,trunk,hybrid,transparent,none','vlan'=>'required_if:mode,tag,trunk,hybrid|nullable|integer|min:1|max:4094','priority'=>'required|integer|min:0|max:7'],
            'onu_service'=>['gem_id'=>'required|integer|min:1|max:128','mapping_type'=>'required|in:vlan,untag','vlan'=>'required_if:mapping_type,vlan|nullable|integer|min:1|max:4094','priority'=>'required|integer|min:0|max:7','uni_type'=>'required|in:none,iphost,ethuni','uni'=>['required_unless:uni_type,none','nullable','string','regex:/^(?:[1-9][0-9]{0,2}|eth_0\/[1-4])$/']],
            'wan'=>['mode'=>'required|in:static,pppoe,dhcp','ip'=>'required_if:mode,static|nullable|ipv4','mask'=>'required_if:mode,static|nullable|ipv4','ip_profile'=>['required_if:mode,static','nullable',...$token],'vlan_profile'=>['required',...$token],'username'=>['required_if:mode,pppoe','nullable',...$token],'password'=>['nullable','string','max:64','regex:/^[A-Za-z0-9_@.()+!#%=:,\/-]+$/'],'host'=>'required|integer|min:1|max:255','ping'=>'required|boolean'],
            'remote'=>['state'=>'required|in:enable,disable','ingress'=>'required|in:lan,wan','protocol'=>'required|in:web,https,ssh,telnet'],
            'tr069'=>['state'=>'required|in:lock,unlock','acs_url'=>['required','string','max:128','url:http,https','regex:/^https?:\/\/[A-Za-z0-9.\-:\/_%?=&+~]+$/'],'auth'=>'required|in:disable,basic','username'=>['required_if:auth,basic','nullable',...$token],'password'=>['nullable','string','max:64','regex:/^[A-Za-z0-9_@.()+!#%=:,\/-]+$/'],'vlan_mode'=>'required|in:tag,untag','vlan'=>'required_if:vlan_mode,tag|nullable|integer|min:1|max:4094','priority'=>'required|integer|min:0|max:7'],
        };
        return array_merge($rules,$extra);
    }
}
