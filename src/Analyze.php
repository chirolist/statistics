<?php

namespace Chirolist\Statistics;

class Analyze
{
    private $upper_limit = 15000;

    private $class_interval = 300;

    private $class_min = 300;

    private $class_max = 12000;

    // 階級値に対する度数
    private $frequency_list = [];
    
    // 階級の範囲リスト
    private $range_list = [];

    private $total_payment_amount = 0;

    public function __construct() {
        $this->setRangeList();
        $this->setFrequencyList();
    }

    /**
     *
     *
     * @return mixed
     */
    public function setRangeList()
    {
        // 階級の幅
        $interval = $this->class_interval;
        // 階級の最小値
        $class_min = $this->class_min;
        // 階級の最大値
        $class_max = $this->class_max;

        // 階級の範囲リスト
        for($n=$class_min; $n<=$class_max; $n+=$interval) {
            $this->range_list[] = $n;
        }
    }

    public function setFrequencyList()
    {
        // 階級の幅
        $interval = $this->class_interval;
        // 階級値に対する度数の初期化
        foreach($this->range_list as $range) {
            $class_mark = $range - floor($interval/2); // 階級値
            $this->frequency_list[$class_mark] = 0;
        }
    }

    public function main2($amount)
    {
        // 階級の幅
        $interval = $this->class_interval;
        // 階級の最小値
        $class_min = $this->class_min;
        // 階級の最大値
        $class_max = $this->class_max;

        // サンプル数
        $count = 0;
        foreach($amount as $val)
        {
            $pay = $val->payment_amount;

            foreach($range_list as $range) {
                $class_mark = $range - floor($interval/2);

                if($range === $class_min) {
                    if($pay < $class_min) {
                        $this->frequency_list[$class_mark]++;
                        break;
                    }
                }

                if($range === $class_max) {
                    if($class_max < $pay) {
                        $this->frequency_list[$class_mark]++;
                        break;
                    }
                }
                
                if($pay < $range) {
                    $this->frequency_list[$class_mark]++;
                    break;
                }
            }

            $count++;
            // 支払額総合計
            $this->total_payment_amount += $pay;
        }
    }

    // 粗データの場合の平均
    public function calcMeanOfRaw($total_payment_amount, $count)
    {
        $mean = round(($total_payment_amount/$count), 2);

        return $mean;
    }

    // 度数分布表データの場合の平均
    public function calcMeanOfFrequency()
    {
        $sum = 0;
        foreach($this->frequency_list as $class_mark=>$frequency) {
            $sum += round(($class_mark*$frequency),2);
        }
        $mean = round(($sum/$count),2);

        return $mean;
    }

    // 粗データの場合の中央値
    public function calcMedianOfRaw($amount)
    {
        if (($count%2) == 0) {
            // 偶数の場合
            $chuou = $count/2;
            $chuou_next = $chuou + 1;
            $obj = $amount[$chuou];
            $obj_next = $amount[$chuou_next];
            $median = round(($obj->payment_amount + $obj_next->payment_amount)/2, 3);
        } else {
            // 奇数の場合
            $chuou = ($count + 1)/2;
            $obj = $amount[$chuou];
            $median = $obj->payment_amount;
        }

        return $median;
    }

    // 度数分布表データの場合の中央値
    public function calcMedianOfFrequency()
    {
        // 階級の幅
        $interval = $this->class_interval;
        $sum_frequency_prev = 0;
        $sum_frequency_next = 0;
        $m = 0;
        $median = 0;
        foreach($this->frequency_list as $class_mark=>$frequency) {
            $m += $frequency;
            if($m <= ($count/2)) {
                $sum_frequency_prev += $frequency; // F(m-1)
                $kagen_class_mark = $class_mark; //境界の階級値
            }else{
                $sum_frequency_next += $frequency; //F(m)
            }
        }
        $median_class_mark = $kagen_class_mark + $interval; // x
        $median_frequency = $this->frequency_list[$median_class_mark]; // fm
        $kagen = $kagen_class_mark + floor($interval/2); // am
        $median += $kagen;
        $uhen = ($count/2) - $sum_frequency_prev;
        $uhen *= $interval;
        $median += round($uhen/$median_frequency,2);

        return $median;
    }
}
