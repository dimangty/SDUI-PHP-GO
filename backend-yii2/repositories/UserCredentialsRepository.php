<?php
declare(strict_types=1);
namespace Shop\repositories;

use Shop\infrastructure\Database;

final class UserCredentialsRepository
{
    public function __construct(private Database $db) {}
    public function byEmail(string $email): ?array {return $this->db->one('SELECT * FROM users WHERE lower(btrim(email))=?',[$email]);}
    public function create(string $id,string $email,string $hash,string $name,string $phone): void
    {
        $this->db->execute('INSERT INTO users(id,email,password_hash,full_name,phone,is_admin,local_identity_ready) VALUES(?,?,?,?,?,FALSE,TRUE)',[$id,$email,$hash,$name,$phone]);
        $this->db->execute('INSERT INTO cart_metadata(user_id,updated_at) VALUES(?,to_timestamp(0))',[$id]);
    }
    public function setHash(string $user,string $hash): void {$this->db->execute('UPDATE users SET password_hash=?,password_changed_at=clock_timestamp() WHERE id=?',[$hash,$user]);}
    public static function sameSnapshot(array $before,array $after): bool
    {
        foreach(['password_hash','session_security_version','sessions_blocked','account_disabled','local_identity_ready'] as $key){if($before[$key]!==$after[$key]){return false;}}
        return true;
    }
}
