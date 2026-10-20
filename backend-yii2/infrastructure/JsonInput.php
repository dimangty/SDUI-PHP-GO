<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\domain\ApiError;

final class JsonInput
{
    /** Schema values: string, ?string, int, bool, strings. All fields optional at wire level, as Go zero values. */
    public static function decode(string $body,string $contentType,array $schema,int $limit=1048576): array
    {
        if(strlen($body)>$limit){throw new ApiError('REQUEST_TOO_LARGE','request body exceeds the configured limit');}
        if(strtolower(trim(explode(';',$contentType)[0]))!=='application/json'){throw new ApiError('UNSUPPORTED_MEDIA_TYPE','Content-Type must be application/json');}
        try{$v=json_decode($body,false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new ApiError('BAD_REQUEST','invalid JSON body');}
        if(!$v instanceof \stdClass){throw new ApiError('BAD_REQUEST','invalid JSON body');}
        $out=(array)$v;
        foreach($out as $key=>$value){
            $type=$schema[$key]??null;
            if($type===null){throw new ApiError('BAD_REQUEST','invalid JSON body');}
            if($value===null && str_starts_with($type,'?')){continue;}
            $ok=match(ltrim($type,'?')){'string'=>is_string($value),'int'=>is_int($value),'bool'=>is_bool($value),'strings'=>is_array($value)&&array_is_list($value)&&count(array_filter($value,'is_string'))===count($value),default=>false};
            if(!$ok){throw new ApiError('BAD_REQUEST','invalid JSON body');}
        }
        return $out;
    }
}
