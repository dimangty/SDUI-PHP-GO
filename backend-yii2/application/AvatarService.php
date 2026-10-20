<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env,JsonInput};
use Shop\repositories\ShopRepository;

final class AvatarService
{
    public function __construct(private Database $db,private ShopRepository $repo) {}
    public function update(string $user,string $contentType,string $body): array
    {
        $data=null;
        if(str_starts_with(strtolower($contentType),'multipart/form-data')){
            $limit=(int)Env::get('AVATAR_BODY_LIMIT_BYTES','5242880');
            if(strlen($body)>$limit){throw new ApiError('FILE_TOO_LARGE',"file exceeds the configured limit of $limit bytes");}
            if(!preg_match('/boundary=(?:"([^"\r\n]{1,70})"|([^;\s]{1,70}))/i',$contentType,$m)){throw new ApiError('BAD_REQUEST','invalid multipart body');}
            $boundary=$m[1]!==''?$m[1]:($m[2]??'');
            foreach(explode('--'.$boundary,$body) as $part){
                if(!str_contains($part,"\r\n\r\n")){continue;}[$headers,$bytes]=explode("\r\n\r\n",$part,2);
                if(strlen($headers)>8192){throw new ApiError('BAD_REQUEST','invalid multipart body');}
                if(preg_match('/Content-Disposition:\s*form-data;[^\r\n]*\bname="file"/i',$headers)){
                    if($data!==null || !str_ends_with($bytes,"\r\n")){throw new ApiError('BAD_REQUEST','invalid multipart body');}
                    $data=substr($bytes,0,-2);
                }
            }
            if($data===null){throw new ApiError('FILE_REQUIRED','file is required');}
            $data=$this->normalize($data);
        }else{
            $input=JsonInput::decode($body,$contentType,['avatarUrl'=>'?string']);
            if(!array_key_exists('avatarUrl',$input)){throw new ApiError('AVATAR_URL_REQUIRED','avatarUrl is required');}
            if($input['avatarUrl']!==null && trim($input['avatarUrl'])!==''){throw new ApiError('AVATAR_UPLOAD_REQUIRED','set an avatar with multipart/form-data; JSON can only clear it');}
        }
        return $this->db->transaction(function()use($user,$data){
            $old=$this->repo->user($user,true)['avatar_url'];$path=null;
            if($data!==null){$path='/uploads/users/'.$user.'/'.Ids::uuid();$this->db->execute('INSERT INTO avatar_files(path,content_type,data) VALUES(?,\'image/png\',decode(?,\'hex\'))',[$path,bin2hex($data)]);}
            $this->db->execute('UPDATE users SET avatar_url=? WHERE id=?',[$path,$user]);
            if($old!==null){$this->db->execute('DELETE FROM avatar_files WHERE path=?',[$old]);}
            return $this->repo->userDto($this->repo->user($user));
        });
    }
    public function normalize(string $data): string
    {
        $info=@getimagesizefromstring($data);
        if(!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF,IMAGETYPE_WEBP],true) || $info[0]<1 || $info[1]<1 || $info[0]>intdiv(16000000,$info[1])){throw new ApiError('INVALID_FILE_TYPE','image cannot be decoded safely');}
        $src=@imagecreatefromstring($data);if(!$src){throw new ApiError('INVALID_FILE_TYPE','image cannot be decoded safely');}
        try{
            $max=max($info[0],$info[1],512);$w=max(1,intdiv($info[0]*512,$max));$h=max(1,intdiv($info[1]*512,$max));
            $target=imagecreatetruecolor($w,$h);imagealphablending($target,false);imagesavealpha($target,true);
            imagecopyresampled($target,$src,0,0,0,0,$w,$h,$info[0],$info[1]);ob_start();imagepng($target);$out=ob_get_clean();imagedestroy($target);return $out;
        }finally{imagedestroy($src);}
    }
    public function download(array $actor,string $owner,string $file): array
    {
        if($actor['id']!==$owner && !$actor['is_admin']){throw new ApiError('FORBIDDEN','uploaded file belongs to another user');}
        if(!Ids::valid($owner) || !Ids::valid($file)){throw new ApiError('FILE_NOT_FOUND','uploaded file not found');}
        $r=$this->db->required('SELECT content_type,encode(data,\'hex\') AS bytes FROM avatar_files WHERE path=?',['/uploads/users/'.$owner.'/'.$file],'FILE_NOT_FOUND','uploaded file not found');
        return ['type'=>$r['content_type'],'data'=>hex2bin($r['bytes'])];
    }
}
