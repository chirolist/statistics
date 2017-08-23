<?php
/**
 * This file is part of the chirolist.statistics library
 *
 * @license http://opensource.org/licenses/MIT
 * @link https://github.com/chirolist/statistics
 * @version 1.0.0
 * @package chirolist.statistics
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Chirolist\Statistics;

/** 
 * 粗データ並びに度数分布表における平均と中央値と最頻値を求めるクラス
 *
 * @package chirolist.statistics
 * @since  1.0.0
 *
 */
class Analyze
{
    // 階級の幅:c
    private $class_interval = 300;

    // 階級の範囲の下限
    private $class_min = 300;

    // 階級の範囲の上限
    private $class_max = 12000;

    // 標本サンプル
    private $sample = [];

    // 階級値に対する度数
    private $frequency_list = [];
    
    // 階級の範囲リスト
    private $range_list = [];

    public function __construct() { }

    /**
     * パラメータ設定
     *
     * @param array $sample 標本サンプル
     * @param int $class_interval 階級の幅
     * @param int $class_min 階級の下限
     * @param int $class_max 階級の上限
     * @throw Exception
     * @return object Analyze
     */
    public function setParam(array $sample, $class_interval = '', $class_min = '', $class_max = '')
    {
        if(empty($sample)) {
            throw Exception('sample is empty');
        }
        if(!ctype_digit($class_interval)) {
            throw InvalidArgumentException('class_interval must be digits');
        }
        if(!ctype_digit($class_min)) {
            throw InvalidArgumentException('class_min must be digits');
        }
        if(!ctype_digit($class_max)) {
            throw InvalidArgumentException('class_max must be digits');
        }
        if(!empty($class_interval) && $class_interval <= 0) {
            throw Exception('class_interval cannot be allowed 0 or minus');
        }
        if(!empty($class_min) && $class_min <= 0) {
            throw Exception('class_min cannot be allowed 0 or minus');
        }
        if(!empty($class_max) && $class_max <= 0) {
            throw Exception('class_max cannot be allowed 0 or minus');
        }
        if(!empty($class_min) && !empty($class_max)) {
            if($class_min > $class_max) {
                throw Exception('class_max must be greater than class_min');
            }
            if(!empty($class_interval) && (($class_max - $class_min) < $class_interval)) {
                throw Exception('the difference between class_max and class_min must be greater than class_interval');
            }
        }

        $this->sample = $sample;

        if(!empty($class_interval)) $this->class_interval = $class_interval;
        if(!empty($class_min))      $this->class_min = $class_min;
        if(!empty($class_max))      $this->class_max = $class_max;

        // 階級の範囲設定
        $this->setRangeList();
        // 度数分布表作成
        $this->setFrequencyList();

        return $this;
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

        foreach($this->range_list as $range) {
            $class_mark = $range - floor($interval/2); // 階級値
            $this->frequency_list[$class_mark] = 0; // 度数の初期化
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

        // 階級値に対する度数の数を初期化する
        $this->initFrequencyList();

        // 標本群を回し、サンプルがどの階級の範囲に収まるのか、その度数を集計する
        foreach($sample as $value)
        {
            // 階級の範囲を下限から上限まで回し、サンプルをふるいにかける
            foreach($this->range_list as $range) {

                // 階級値
                $class_mark = $range - floor($interval/2); // x(m)

                // 下限の階級より下位に収まる場合
                if($range === $class_min) {
                    if($value < $class_min) {
                        $this->frequency_list[$class_mark]++;
                        break;
                    }
                }

                // 上限の階級より上位に収まる場合
                if($range === $class_max) {
                    if($class_max < $value) {
                        $this->frequency_list[$class_mark]++;
                        break;
                    }
                }
                
                // 各階級の範囲内に収まる場合
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

        return (int)$mean;
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

        return (int)$mean;
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
            $sample_mid_prev = current(array_slice($sample, ($n/2), 1, true)); // 小さい方からn/2番目の標本
            $sample_mid_next = current(array_slice($sample, ($n/2)+1, 1, true)); // 小さい方からn/2+1番目の標本
            $median = round(($sample_mid_prev+$sample_mid_next)/2, 3);
        } else {
            // 奇数の場合
            $median = $sample_mid = current(array_slice($sample, ($n+1)/2, 1, true)); // 小さい方から(n+1)/2番目の標本
        }

        return (int)$median;
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

        return (int)$median;
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
                $prev_class_mark = $class_mark; // F(m-1)の階級値:f(m-1)
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
    public function calcModeOfRaw()
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
    public function calcModeOfFrequency()
    {
        // 階級の幅
        $interval = $this->class_interval; // c

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

        return (int)$mode;
    }
}
