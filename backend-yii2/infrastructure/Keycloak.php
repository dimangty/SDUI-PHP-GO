<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\domain\ApiError;

final class Keycloak
{
    private string $issuer;
    private string $client;
    private string $adminBase;
    private HttpClient $http;
    public function __construct(?HttpClient $http=null)
    {
        $this->http=$http??new HttpClient();
        $this->issuer=rtrim(Env::get('KEYCLOAK_ISSUER'),'/');$this->client=Env::get('KEYCLOAK_CLIENT_ID','shopkmp-backend');
        HttpClient::origin($this->issuer);
        if(!preg_match('~^(.*)/realms/([^/]+)$~D',$this->issuer,$m) || Env::get('KEYCLOAK_CLIENT_SECRET')===''){throw new \RuntimeException('KEYCLOAK_ISSUER and client credentials required');}
        $this->adminBase=$m[1].'/admin/realms/'.rawurlencode($m[2]);
    }
    private function form(string $path,array $values): array
    {
        $values+=['client_id'=>$this->client,'client_secret'=>Env::get('KEYCLOAK_CLIENT_SECRET')];
        try{return $this->http->request('POST',$this->issuer.'/protocol/openid-connect/'.$path,['Content-Type'=>'application/x-www-form-urlencoded'],http_build_query($values));}
        catch(\Throwable){throw new ApiError('IDENTITY_PROVIDER_UNAVAILABLE','identity provider is unavailable');}
    }
    public function tokens(array $values): array
    {
        $r=$this->form('token',$values);
        if($r['status']<200 || $r['status']>=300){
            if(in_array($r['status'],[400,401],true)){
                $text=strtolower($r['body']);if(str_contains($text,'temporarily disabled') || str_contains($text,'account disabled') || str_contains($text,'locked')){throw new ApiError('ACCOUNT_LOCKED','account is temporarily locked');}
                throw new ApiError('INVALID_CREDENTIALS','invalid credentials');
            }throw new ApiError('IDENTITY_PROVIDER_ERROR','identity provider rejected the request');
        }
        try{$v=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){$v=[];}
        if(!is_string($v['access_token']??null) || !is_int($v['expires_in']??null)){throw new ApiError('IDENTITY_PROVIDER_ERROR','identity provider returned an invalid token response');}
        $parts=explode('.',$v['access_token']);$claims=count($parts)===3?json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($parts[1]),true):[];
        return ['userId'=>$claims['sub']??'','accessToken'=>$v['access_token'],'refreshToken'=>$v['refresh_token']??'','expiresIn'=>$v['expires_in']];
    }
    public function password(string $email,string $password): array
    {return $this->tokens(['grant_type'=>'password','username'=>strtolower(trim($email)),'password'=>$password,'scope'=>'openid offline_access']);}
    public function admin(string $method,string $path,?array $body=null): array
    {
        $token=Env::get('KEYCLOAK_ADMIN_TOKEN');
        if($token===''){
            try{$token=$this->tokens(['grant_type'=>'client_credentials'])['accessToken'];}
            catch(ApiError $e){
                // Backend service credentials cannot produce a caller's login error.
                if($e->errorCode==='IDENTITY_PROVIDER_UNAVAILABLE'){throw $e;}
                throw new ApiError('IDENTITY_PROVIDER_ERROR','identity provider service authentication failed');
            }
        }
        try{return $this->http->request($method,$this->adminBase.$path,['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));}
        catch(\Throwable){throw new ApiError('IDENTITY_PROVIDER_UNAVAILABLE','identity provider is unavailable');}
    }
    /** Keycloak 26.3.3 LogoutEndpoint.logoutToken: only empty 204 confirms success.
     * Missing sessions already return 204. A 400 invalid_grant is NOT success.
     */
    public function logout(string $refresh): void {$this->expectNoContent($this->form('logout',['refresh_token'=>$refresh]));}
    private function expectNoContent(array $response): void
    {
        if($response['status']!==204 || $response['body']!==''){throw new ApiError('IDENTITY_PROVIDER_ERROR','identity provider did not confirm session revocation');}
    }
    /** Safe authenticated subject, never a user-supplied refresh token. Requires
     * realm-management manage-users (or equivalent fine-grained user permission).
     */
    public function logoutUser(string $user): void
    {
        if(!\Shop\domain\Ids::valid($user)){throw new ApiError('IDENTITY_PROVIDER_ERROR','invalid identity subject');}
        $path='/users/'.rawurlencode($user);
        $this->expectNoContent($this->admin('POST',$path.'/logout'));
        $r=$this->admin('DELETE',$path.'/consents/'.rawurlencode($this->client));
        if($r['status']!==404){$this->expectNoContent($r);}
    }
    public function resetPassword(string $user,string $password): void
    {
        if(!\Shop\domain\Ids::valid($user)){throw new ApiError('IDENTITY_PROVIDER_ERROR','invalid identity subject');}
        $this->expectNoContent($this->admin('PUT','/users/'.rawurlencode($user).'/reset-password',['type'=>'password','value'=>$password,'temporary'=>false]));
    }
    public function verifier(): OidcVerifier {return new OidcVerifier($this->issuer,$this->client);}
}
