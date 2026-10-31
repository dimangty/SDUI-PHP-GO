<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Semantic completeness is required even when the transport returned HTTP 200. */
final class SearchResponse
{
    public static function decode(array $response): \stdClass
    {
        $value=json_decode($response['body'],false,64,JSON_THROW_ON_ERROR);
        if($response['status']!==200 || !$value instanceof \stdClass || property_exists($value,'error')){
            throw new \UnexpectedValueException('Invalid product search response');
        }
        return $value;
    }
    public static function complete(\stdClass $value): array
    {
        $shards=$value->_shards??null;$hits=$value->hits??null;$total=$hits->total??null;
        if(($value->timed_out??null)!==false
            || property_exists($value,'terminated_early') && $value->terminated_early!==false
            || !$shards instanceof \stdClass || !is_int($shards->total??null) || $shards->total<1
            || !is_int($shards->successful??null) || $shards->successful!==$shards->total
            || ($shards->failed??null)!==0
            || property_exists($shards,'skipped') && (!is_int($shards->skipped) || $shards->skipped<0 || $shards->skipped>$shards->successful)
            || property_exists($shards,'failures') && $shards->failures!==[]
            || !$hits instanceof \stdClass || !$total instanceof \stdClass
            || !is_int($total->value??null) || $total->value<0 || ($total->relation??null)!=='eq'
            || !is_array($hits->hits??null) || !array_is_list($hits->hits)){
            throw new \UnexpectedValueException('Incomplete product search response');
        }
        $rows=[];
        foreach($hits->hits as $hit){
            if(!$hit instanceof \stdClass){throw new \UnexpectedValueException('Invalid product search hit');}
            $rows[]=get_object_vars($hit);
        }
        return ['total'=>$total->value,'hits'=>$rows];
    }
}
