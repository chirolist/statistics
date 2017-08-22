<?php

namespace Chirolist\Statistics;

class Analyze
{
    private $class_interval = 300;

    private $class_min = 300;

    private $class_max = 12000;

    // 標本サンプル
    private $sample = [];

    // 階級値に対する度数
    private $frequency_list = [];
    
    // 階級の範囲リスト
    private $range_list = [];

    public function __construct() {
    }

    /**
     * パラメータ設定
     *
     * @param array $sample 標本サンプル
     * @param int $class_interval 階級の幅
     * @param int $class_min 階級の下限
     * @param int $class_max 階級の上限
     * @throw Exception
     */
    public function setParam($sample, $class_interval, $class_min, $class_max)
    {
        if(empty($sample)) {
            throw Exception('sample is empty');
        }
        if($class_interval <= 0) {
            throw Exception('class_interval cannot be allowed 0 or minus');
        }
        if($class_min <= 0) {
            throw Exception('class_min cannot be allowed 0 or minus');
        }
        if($class_max <= 0) {
            throw Exception('class_max cannot be allowed 0 or minus');
        }
        if($class_min > $class_max) {
            throw Exception('class_max must be greater than class_min');
        }
        if(($class_max - $class_min) < $class_interval) {
            throw Exception('the difference between class_max and class_min must be greater than class_interval');
        }

        $this->sample = $sample;
        $this->class_interval = $class_interval;
        $this->class_min = $class_min;
        $this->class_max = $class_max;

        // 階級の範囲設定
        $this->setRangeList();
        // 度数分布表作成
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
     * @return void
     */
    public function setFrequencyList()
    {
        // 標本サンプル
        $sample = $this->sample;
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
     * @return int $mean 平均
     */
    public function calcMeanOfRaw()
    {
        // 標本サンプル
        $sample = $this->sample;

        $sum  = round(array_sum($sample),2);
        $n    = count($sample);
        $mean = round($sum/$n,2);

        return $mean;
    }

    /**
     * 度数分布表データの場合の平均
     *
     * @return int $mean 平均
     */
    public function calcMeanOfFrequency()
    {
        // 標本サンプル
        $sample = $this->sample;

        $sum = 0;
        foreach($this->frequency_list as $class_mark=>$frequency) {
            $sum += round(($class_mark*$frequency),2);
        }
        $n    = count($sample);
        $mean = round(($sum/$n),2);

        return $mean;
    }

    /**
     * 粗データの場合の中央値
     *
     * @return int $median 中央値
     */
    public function calcMedianOfRaw()
    {
        // 標本サンプル
        $sample = $this->sample;

        $n = count($sample);
        if (($n%2) == 0) {
            // 偶数の場合
            $half_sample      = current(array_slice($sample, ($n/2), 1, true)); // 小さい方からn/2番目の標本
            $half_sample_next = current(array_slice($sample, ($n/2)+1, 1, true)); // 小さい方から(n+1)/2番目の標本
            $median = round(($half_sample+$half_sample_next)/2, 3);
        } else {
            // 奇数の場合
            $half_sample = current(array_slice($sample, ($n+1)/2, 1, true)); // 小さい方から(n+1)/2番目の標本
            $median = $half_sample;
        }

        return $median;
    }

    /**
     * 度数分布表データの場合の中央値
     *
     * @return int $median 中央値
     */
    public function calcMedianOfFrequency()
    {
        // 標本サンプル
        $sample = $this->sample;
        // 階級の幅
        $interval = $this->class_interval;

        list($prev_class_mark, $sum_frequency_prev, $sum_frequency_next) = $this->calcCumulativeFrequency(count($sample));

        // 中央値を含む階級の階級値
        $median_class_mark = $prev_class_mark+$interval; // x(m)

        // 中央値を含む階級の階級値の度数
        $median_frequency  = $this->frequency_list[$median_class_mark]; // fm

        // 中央値を含む階級の下限
        $am = $prev_class_mark + floor($interval/2);

        // fmを比例配分して階級mの下限amからの距離xを求める
        $n = count($sample);
        $numer = (round($n/2,2)-$sum_frequency_prev)*$interval;
        $x = round($numer/$median_frequency,2);

        $median = $am+$x;

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
                $prev_class_mark = $class_mark; // F(m-1)の階級値
            }else{
                $sum_frequency_next += $frequency; //F(m)
            }
        }

        return array($prev_class_mark, $sum_frequency_prev, $sum_frequency_next);
    }

    /**
     * 粗データの最頻値
     *
     * @return int $mode 最頻値
     */
    public static function calcModeOfRaw()
    {
        // 標本サンプル
        $sample = $this->sample;

        $sample_numbers = array_count_values($sample);
        $max            = max($sample_numbers);

        $mode = array_search($max, $sample_numbers);

        return (int)$mode;
    }

    /**
     * 度数分布表データの最頻値
     *
     * @return int $mode 最頻値
     */
    public function calcModeOfOfFrequency()
    {
        // 標本サンプル
        $sample = $this->sample;
        // 階級の幅
        $interval = $this->class_interval;

        // 最頻値を含む階級の度数
        $max_frequency = max($this->frequency_list); // f(m)
        // 最頻値を含む階級の階級値
        $max_class_mark = array_search($max_frequency, $this->frequency_list); // x(m)

        // 最頻値を含む階級の１つ前の階級値
        $class_mark_prev = $max_class_mark-$interval; // x(m-1)
        // 最頻値を含む階級の１つ前の階級値の度数
        $max_frequency_prev = $this->frequency_list[$class_mark_prev]; // f(m-1)
        // 最頻値を含む階級の１つ後ろの階級値
        $class_mark_next = $max_class_mark+$interval; // x(m+1)
        // 最頻値を含む階級の１つ後ろの階級値の度数
        $max_frequency_next = $this->frequency_list[$class_mark_next]; // f(m+1)

        // 最頻値を含むの階級の下限
        $am = $class_mark_prev + floor($interval/2); //am

        // (fm-f(m-1))*c
        $numer = ($max_frequency-$max_frequency_prev)*$interval;
        // fm-f(m-1)+fm-f(m+1)
        $denom = $max_frequency-$max_frequency_prev+$max_frequency-$max_frequency_next;
        // fmを比例配分して階級mの下限amからの距離x
        $x = round($numer/$denom,2);
        $mode = $am+$x;

        return $mode;
    }
}
