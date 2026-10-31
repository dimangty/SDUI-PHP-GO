<?php
declare(strict_types=1);
namespace Shop\controllers;

/** Passwords come only from a real controlling terminal, with echo disabled. */
final class AuthPasswordInput
{
    public static function read(): string
    {
        if(PHP_SAPI!=='cli' || !defined('STDIN') || !stream_isatty(STDIN) || !function_exists('pcntl_signal')){throw new \RuntimeException('TTY_REQUIRED');}
        $tty=@fopen('/dev/tty','r+');
        if($tty===false || !stream_isatty($tty)){if(is_resource($tty)){fclose($tty);}throw new \RuntimeException('TTY_REQUIRED');}
        exec('stty -g < /dev/tty 2>/dev/null',$modes,$code);
        $mode=$modes[0]??'';
        if($code!==0 || !preg_match('/^[0-9a-f:;]+$/Di',$mode)){fclose($tty);throw new \RuntimeException('TTY_REQUIRED');}
        $restored=false;
        $restore=static function()use($mode,&$restored){if(!$restored){exec('stty '.escapeshellarg($mode).' < /dev/tty 2>/dev/null');$restored=true;}};
        register_shutdown_function($restore);
        $async=pcntl_async_signals(true);$handlers=[];
        foreach([SIGINT,SIGTERM,SIGHUP,SIGQUIT] as $signal){$handlers[$signal]=pcntl_signal_get_handler($signal);pcntl_signal($signal,static function(){throw new \RuntimeException('TTY_CANCELLED');});}
        try{
            exec('stty -echo -echonl -icanon -isig min 1 time 0 < /dev/tty 2>/dev/null',$unused,$code);
            if($code!==0){throw new \RuntimeException('TTY_REQUIRED');}
            $read=static function(string $prompt)use($tty): string {
                fwrite($tty,$prompt);$value='';
                while(true){
                    $char=fread($tty,1);
                    if($char===false || $char==='' || $char==="\x03" || $char==="\x04"){throw new \RuntimeException('TTY_CANCELLED');}
                    if($char==="\n" || $char==="\r"){fwrite($tty,"\n");return $value;}
                    if($char==="\x7f" || $char==="\x08"){$value=substr($value,0,-1);continue;}
                    if(ord($char)<32 || strlen($value)>=128){throw new \RuntimeException('TTY_PASSWORD_INVALID');}
                    $value.=$char;
                }
            };
            $password=$read('New password: ');$confirm=$read('Confirm password: ');
            if(!hash_equals($password,$confirm)){throw new \RuntimeException('TTY_PASSWORD_MISMATCH');}
            return $password;
        }finally{
            $restore();foreach($handlers as $signal=>$handler){pcntl_signal($signal,$handler);}pcntl_async_signals($async);fclose($tty);
        }
    }
}
