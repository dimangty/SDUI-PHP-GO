<?php
declare(strict_types=1);
namespace Shop\controllers;

use Shop\application\{LocalIdentityMigration,SessionService};
use Shop\domain\ApiError;
use Shop\infrastructure\{Database,DatabaseRole,Env};
use Shop\repositories\ShopRepository;

/** Deliberately registered only in config/console.php, never in the HTTP route map. */
final class AuthController extends \yii\console\Controller
{
    public string $apply='no';
    public string $admin='';
    public string $disabled='';
    public string $ownership='';
    public string $identity='';
    public string $operation='';
    public string $outcome='';
    public string $orphan='';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID),match($actionID){
            'attest'=>['apply','admin','disabled'],
            'set-disabled'=>['apply','disabled'],
            'reset-password'=>['apply','ownership','identity','operation','outcome','orphan'],
            default=>[],
        });
    }
    private function service(): LocalIdentityMigration
    {
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
        if (Env::production()) { DatabaseRole::identity($db, 'operator'); }
        return new LocalIdentityMigration($db,new SessionService($db,$repo));
    }
    private function reviewedState(string $value): bool
    {
        return match($value){'yes'=>true,'no'=>false,default=>throw new ApiError('MIGRATION_EXPLICIT_STATE_REQUIRED','Use yes or no for every reviewed state')};
    }
    private function explicitApply(): void
    {
        if($this->apply!=='yes'){throw new ApiError('MIGRATION_APPLY_REQUIRED','Use --apply=yes for a reviewed action');}
    }
    private function execute(\Closure $work): int
    {
        // Never send exception arguments/driver messages to the terminal or Yii logs.
        ini_set('zend.exception_ignore_args','1');
        try{$this->stdout(json_encode($work(),JSON_THROW_ON_ERROR)."\n");return 0;}
        catch(ApiError $e){$this->stderr($e->errorCode."\n");return 2;}
        catch(\Throwable $e){$this->stderr(in_array($e->getMessage(),['TTY_REQUIRED','TTY_CANCELLED','TTY_PASSWORD_INVALID','TTY_PASSWORD_MISMATCH'],true)?$e->getMessage()."\n":"MIGRATION_FAILED_REVIEW_TICKET\n");return 2;}
    }
    /** Read-only, count-only dry run. No identifiers or verifier samples are emitted. */
    public function actionInventory(): int {return $this->execute(fn()=>$this->service()->inventory());}
    public function actionAttest(string $user,string $ticket): int
    {
        return $this->execute(function()use($user,$ticket){
            $this->explicitApply();return $this->service()->attest($user,$ticket,$this->reviewedState($this->admin),$this->reviewedState($this->disabled),true);
        });
    }
    public function actionSetDisabled(string $user,string $ticket): int
    {
        return $this->execute(function()use($user,$ticket){
            $this->explicitApply();return $this->service()->setDisabled($user,$ticket,$this->reviewedState($this->disabled),true);
        });
    }
    public function actionResetPassword(string $user,string $ticket): int
    {
        return $this->execute(function()use($user,$ticket){
            $this->explicitApply();
            if($this->ownership!=='verified-out-of-app'){throw new ApiError('MIGRATION_OWNERSHIP_REQUIRED','Verify ownership outside the application first');}
            if($this->orphan!=='' && $this->orphan!=='confirmed-investigated'){throw new ApiError('MIGRATION_RECOVERY_CONFIRMATION_REQUIRED','Investigate the orphan fence independently');}
            return $this->service()->resetPassword($user,$ticket,AuthPasswordInput::read(),true,true,$this->operation===''?null:$this->operation,$this->outcome===''?null:$this->outcome,$this->identity==='confirmed',$this->orphan==='confirmed-investigated');
        });
    }
}
