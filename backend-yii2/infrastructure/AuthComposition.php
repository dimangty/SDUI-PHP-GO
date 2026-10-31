<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\application\{AuthService,LoginThrottle,SessionService};
use Shop\domain\PasswordHasher;
use Shop\repositories\{ShopRepository,UserCredentialsRepository};

/** One explicit local-auth composition root; no container or per-class interfaces. */
final class AuthComposition
{
    public static function create(Database $db, ShopRepository $repo, SessionService $sessions, ?PasswordHasher $hasher = null): AuthService
    {
        return new AuthService($db, $repo, $sessions, new UserCredentialsRepository($db), new LoginThrottle($db), $hasher ?? new PasswordHasher());
    }
}
