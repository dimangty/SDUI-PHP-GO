<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Operator-configured origins only. No redirects, bounded response, verified TLS, no mutation retries. */
final class HttpClient
{
    public function __construct(private readonly float $timeout=10,private readonly int $limit=1048576)
    {
        if(!is_finite($timeout) || $timeout<=0 || $limit<0){throw new \InvalidArgumentException('Invalid HTTP deadline or response limit');}
    }
    public static function origin(string $url): string
    {
        $u=parse_url($url);
        if(!$u || !in_array($u['scheme']??'', ['http','https'],true) || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['fragment'])) {throw new \RuntimeException('Invalid configured HTTP origin');}
        if(Env::production() && $u['scheme']!=='https'){throw new \RuntimeException('Production adapters require HTTPS');}
        return $u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'');
    }
    public function request(string $method,string $url,array $headers=[],?string $body=null): array
    {
        self::origin($url);$lines=[];
        foreach($headers as $k=>$v){if(strpbrk($k.$v,"\r\n")!==false){throw new \RuntimeException('Invalid outbound header');}$lines[]=$k.': '.$v;}
        $curl=curl_init($url);$response='';$responseHeaders=[];$headerBytes=0;$limitExceeded=false;
        // libcurl's total timeout covers DNS/connect/TLS, request upload, response
        // headers and body. A stream I/O timeout cannot enforce that absolute budget.
        $milliseconds=max(1,(int)ceil($this->timeout*1000));
        curl_setopt_array($curl,[
            CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_HTTPHEADER=>$lines,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS=>$milliseconds,
            CURLOPT_CONNECTTIMEOUT_MS=>$milliseconds,
            CURLOPT_NOSIGNAL=>true,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
            CURLOPT_WRITEFUNCTION=>function($handle,string $chunk)use(&$response,&$limitExceeded): int {
                if(strlen($chunk)>$this->limit-strlen($response)){$limitExceeded=true;return 0;}
                $response.=$chunk;return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>function($handle,string $line)use(&$responseHeaders,&$headerBytes,&$limitExceeded): int {
                $headerBytes+=strlen($line);if($headerBytes>65536){$limitExceeded=true;return 0;}
                if(preg_match('/^HTTP\/\S+ \d+/',$line)){$responseHeaders=[];}
                elseif(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$responseHeaders[strtolower($key)]=trim($value);}
                return strlen($line);
            },
        ]);
        if($body!==null){curl_setopt($curl,CURLOPT_POSTFIELDS,$body);}
        if($method==='HEAD'){curl_setopt($curl,CURLOPT_NOBODY,true);}
        try{
            $ok=curl_exec($curl);$errno=curl_errno($curl);
            if($limitExceeded){throw new \RuntimeException('Upstream response limit exceeded');}
            if($errno===CURLE_OPERATION_TIMEDOUT){throw new \RuntimeException('Upstream request timeout exceeded');}
            if($ok===false){throw new \RuntimeException('Configured upstream unavailable');}
            $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$response];
        }finally{unset($curl);}
    }
}
