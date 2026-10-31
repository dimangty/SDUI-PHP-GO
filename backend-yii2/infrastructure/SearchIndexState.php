<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Physical-index metadata, never a sentinel document affecting product totals. */
final class SearchIndexState
{
    public static function same(array $left,array $right): bool
    {
        // OpenSearch serializes _meta map keys in its own order. Values/types
        // remain strict; JSON object property order has no lifecycle meaning.
        ksort($left);ksort($right);return $left===$right;
    }
    public static function inspect(array $response,string $index,bool $requireReady=true): array
    {
        $value=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
        $entry=$value[$index]??null;$uuid=$entry['settings']['index']['uuid']??null;
        $meta=$entry['mappings']['_meta']['shopkmp_products']??null;
        if($response['status']!==200 || !is_array($value) || count($value)!==1 || !is_string($uuid) || $uuid===''
            || !is_array($meta) || ($meta['version']??null)!==1 || !in_array($meta['state']??null,['building','ready'],true)
            || !is_string($meta['build']??null) || preg_match('/^[a-f0-9]{32}$/D',$meta['build'])!==1){
            throw new \UnexpectedValueException('Product index is not verified ready');
        }
        if($requireReady && (($meta['state']??null)!=='ready' || ($meta['uuid']??null)!==$uuid
            || !is_int($meta['documents']??null) || $meta['documents']<0
            || !is_string($meta['identitySHA256']??null) || preg_match('/^[a-f0-9]{64}$/D',$meta['identitySHA256'])!==1)){
            throw new \UnexpectedValueException('Product index is not verified ready');
        }
        return ['uuid'=>$uuid,'meta'=>$meta];
    }
}
