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

    public function __construct() {
        $this->setRangeList();
        $this->setFrequencyList();
    }

    /**
     * 階級の範囲をセットする
     * 下限~上限の範囲で、階級の幅ごとに区切る
     *
     * @return void
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

    /**
     * 階級値に対する度数の数を初期化する
     *
     * @param array $sample 標本数
     * @return void
     */
    public function initFrequencyList()
    {
        // 階級の幅
        $interval = $this->class_interval;
        // 階級値に対する度数の初期化
        foreach($this->range_list as $range) {
            $class_mark = $range - floor($interval/2); // 階級値
            $this->frequency_list[$class_mark] = 0;
        }
    }

    /**
     * 階級値に対する度数の数を集計する
     *
     * @param array $sample 標本数
     * @return void
     */
    public function setFrequencyList($sample)
    {
        // 階級の幅
        $interval = $this->class_interval;
        // 階級の最小値
        $class_min = $this->class_min;
        // 階級の最大値
        $class_max = $this->class_max;

        $this->initFrequencyList();

        foreach($sample as $value)
        {
            foreach($this->range_list as $range) {
                $class_mark = $range - floor($interval/2);

                if($range === $class_min) {
                    if($value < $class_min) {
                        $this->frequency_list[$class_mark]++;
                        break;
                    }
                }

                if($range === $class_max) {
                    if($class_max < $value) {
                        $this->frequency_list[$class_mark]++;
                        break;
                    }
                }
                
                if($value < $range) {
                    $this->frequency_list[$class_mark]++;
                    break;
                }
            }
        }
    }

    /**
     * 粗データの場合の平均
     *
     * @param int $sum 標本サマリー
     * @param int $n 標本数
     * @return int $mean 平均
     */
    public function calcMeanOfRaw($sum, $n)
    {
        $mean = round(($sum/$n), 2);

        return $mean;
    }

    /**
     * 度数分布表データの場合の平均
     *
     * @return int $mean 平均
     */
    public function calcMeanOfFrequency()
    {
        $sum = 0;
        foreach($this->frequency_list as $class_mark=>$frequency) {
            $sum += round(($class_mark*$frequency),2);
        }
        $mean = round(($sum/$count),2);

        return $mean;
    }

    /**
     * 粗データの場合の中央値
     *
     * @param array $sample 標本サンプル
     * @return int $median 中央値
     */
    public function calcMedianOfRaw($sample)
    {
        $count = count($sample);
        if (($count%2) == 0) {
            // 偶数の場合
            $half = $count/2;
            $half_next = $chuou + 1;
            //$obj = $sample[$chuou];
            $half_sample = current(array_slice($sample, $half, 1, true));
            //$obj_next = $sample[$chuou_next];
            $half_sample_next = current(array_slice($sample, $half_next, 1, true));
            $median = round(($half_sample + $half_sample_next)/2, 3);
        } else {
            // 奇数の場合
            $half = ($count + 1)/2;
            //$obj = $amount[$chuou];
            $half_sample = current(array_slice($sample, $half, 1, true));
            $median = $half_sample;
        }

        return $median;
    }

    /**
     * 度数分布表データの場合の中央値
     *
     * @param array $sample 標本サンプル
     * @return int $median 中央値
     */
    public function calcMedianOfFrequency($sample)
    {
        $count = count($sample);
        // 階級の幅
        $interval = $this->class_interval;

        list($sum_frequency_prev, $sum_frequency_next) = $this->calcCumulativeFrequency($count);

        $median_class_mark = $kagen_class_mark + $interval; // x
        $median_frequency = $this->frequency_list[$median_class_mark]; // fm
        $kagen = $kagen_class_mark + floor($interval/2); // am

        $uhen = ($count/2) - $sum_frequency_prev;
        $uhen *= $interval;

        $median = 0;
        $median += $kagen;
        $median += round($uhen/$median_frequency,2);

        return $median;
    }

    /**
     * 累積度数（F(m-1)とF(m)）を求める
     *
     * @param int $count
     * @return array $sum_frequency_prev:F(m-1) $sum_frequency_next:F(m)
     */
    public function calcCumulativeFrequency($count)
    {
        $sum_frequency_prev = 0;
        $sum_frequency_next = 0;
        $m = 0;
        foreach($this->frequency_list as $class_mark=>$frequency) {
            $m += $frequency;
            if($m <= ($count/2)) {
                $sum_frequency_prev += $frequency; // F(m-1)
                $kagen_class_mark = $class_mark; //境界の階級値
            }else{
                $sum_frequency_next += $frequency; //F(m)
            }
        }

        return array($sum_frequency_prev, $sum_frequency_next);
    }
}
