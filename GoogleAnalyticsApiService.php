<?php


namespace App\Services\Admin;

use App\Entities\AnalyticsPageViews;
use Google\Analytics\Data\V1beta\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Illuminate\Support\Facades\Log;

class GoogleAnalyticsApiService
{
    private $property_id;

    public function __construct()
    {
        $this->property_id = config('analytics.google_analytics_property_id');
    }

    private function initialize()
    {
        $file_path = base_path('google_application_credentials.json');

        $file_is_exists = file_exists($file_path);
        if ($file_is_exists){
            return new BetaAnalyticsDataClient([
                'credentials' => $file_path,
            ]);
        }

        Log::error('The api credentials file is not found');
        return false;
    }

    /**
     * [APIリクエストフォーマット]
     * GoogleアナリティクスAPIへ投げる際のリクエスト定型文。
     * APIに向かって色々投げ方があるが、後から参加した開発者もすぐ投げやすいようにテンプレ化する
     *
     * 何もデータが取得できなかった場合は、空の配列が返却される
     * アナリティクスレポートは24～48時間ほどタイムラグがあるので、昨日など直近のデータ取得できない場合がある
     *
     * $dimensions_names、$metrics_namesで指定できる内容は公式ドキュメントを参照
     *
     * 公式ドキュメント
     * https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema#metrics
     *
     * @param $start_date
     * @param $end_date
     * @param array $dimensions_names
     * @param array $metrics_names
     * @return array
     */
    private function apiRequest($start_date,$end_date,$dimensions_names,$metrics_names)
    {
        // 返却値セット
        $data = [];

        // apiとの接続準備
        $client = $this->initialize();
        if (!$client){
            Log::error('GoogleAnalytics API initialization failed');
            return null;
        }

        // property_idの存在チェック
        if ( is_null($this->property_id) || $this->property_id === '' ){
            Log::error('GoogleAnalytics property_id is not found');
            return null;
        }

        // ディメンション用のオブジェクト生成（ディメンションは複数指定できるので、配列でApiにわたす）
        $dimensions = [];
        $count_dimensions = count($dimensions_names);
        foreach ($dimensions_names as $value){
            array_push($dimensions,new Dimension(['name' => $value,]));
        }

        // メトリクス用のオブジェクト生成（メトリクスは複数指定できるので、配列でApiにわたす）
        $metrics = [];
        $count_metrics = count($metrics_names);
        foreach ($metrics_names as $value){
            array_push($metrics,new Metric(['name' => $value,]));
        }

        // apiへリクエスト
        $response = $client->runReport([
            'property' => 'properties/' . $this->property_id,
            'dateRanges' => [
                new DateRange([
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                ]),
            ],
            'dimensions' => $dimensions,
            'metrics'    => $metrics
        ]);

        // apiから返ってきたレスポンスをわかりやすいように整形
        $num  = 0;
        foreach ($response->getRows() as $row) {
            for ($i = 0; $i < $count_dimensions; $i++){
                $data[$num][$dimensions_names[$i]] = $row->getDimensionValues()[$i]->getValue();
            }
            for ($i = 0; $i < $count_metrics; $i++){
                $data[$num][$metrics_names[$i]] = $row->getMetricValues()[$i]->getValue();
            }
            $num++;
        }

        return $data;
    }

    /**
     * [アクティブユーザー取得]
     * 指定した日付毎にアクティブユーザー数を取得する
     *
     * @param $start_date
     * @param $end_date
     * @return array
     */
    public function getActiveUsers($start_date,$end_date)
    {
        return $this->apiRequest(
            $start_date,
            $end_date,
            array('date'),
            array('activeUsers'));
    }

    /**
     * [ページビュー取得]
     * 指定した期間内のページビュー数をページ単位で取得
     *
     * @param $start_date
     * @param $end_date
     * @return array
     */
    public function getPageView($start_date,$end_date)
    {
        return $this->apiRequest(
            $start_date,
            $end_date,
            array('pagePath','pageTitle'),
            array('screenPageViews','activeUsers'));
    }

    public function createAnalyticsPageView($start_date,$end_date)
    {
        // ページビュー数をapiから取得
        $response = $this->getPageView($start_date,$end_date);

        // データが空の場合は、何もしない
        if ( count($response) < 1 ){
            Log::info('No data from GoogleAnalytics API');
            return false;
        }

        // 既に同じ日付にデータがある場合は、何もしない
        if ( AnalyticsPageViews::where('analytics_date',$end_date)->exists() ){
            Log::error('There is data on the same date from analytics_page_views table');
            return false;
        }

        $this->storeAnalyticsPageView($response,$end_date);
    }

    /**
     * [デモデータモード]
     * GoogleAPIから取得したデータではなく、ダミーデータで作成するようにする
     * （デモ環境などアクセス数が乏しい環境を想定）
     */
    public function createDemoAnalyticsPageView($date)
    {
        $response = $this->getDummyPageView();
        $this->storeAnalyticsPageView($response,$date);
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getDummyPageView(){
        $page_pattern = $this->getPathPattern();

        $response = [];
        foreach ($page_pattern as $value){
            $response[] = array(
                'pagePath'        => $value['path'],
                'screenPageViews' => random_int($value['view_min'],$value['view_max']),
                'activeUsers'     => random_int($value['user_min'],$value['user_max']),
            );
        }
        return $response;
    }


//    /**
//     * [デモデータ一括作成]
//     * デモ環境で一括でデータ作成したい場合に利用する
//     * デフォルト：過去30日分を作成
//     *
//     */
//    public function createBulkDemoAnalyticsPageView()
//    {
//        $dummy_data = $this->getDummyData();
//        foreach ($dummy_data as $day => $data){
//            $this->storeAnalyticsPageView($data,$day);
//        }
//    }

//    public function getDummyData(){
//        $page_pattern = $this->getPathPattern();
//
//        $days = [];
//        for($i = 30; $i > 0; $i--){
//            $day = '-'. $i .' day';
//            $days[] = date("Y-m-d", strtotime($day));
//        }
//
//        $response = [];
//        foreach ($days as $day){
//            foreach ($page_pattern as $value){
//                $response[$day][] = array(
//                    'pagePath'        => $value['path'],
//                    'screenPageViews' => random_int($value['view_min'],$value['view_max']),
//                    'activeUsers'     => random_int($value['user_min'],$value['user_max']),
//                );
//            }
//        }
//
//        return $response;
//    }

    /**
     * デモデータ作成時のデータパターン
     *
     * $pattern配列に意味
     * 　[0] ページパス
     * 　[1] PV数の最小値
     * 　[2] PV数の最大値
     * 　[3] ユーザ数の最小値
     * 　[4] ユーザ数の最大値
     *
     * @return array
     */
    private function getPathPattern(){
        $pattern = [
            [ '/', 2000, 2300,300,400],
            [ '/floorguide', 1900, 2100, 200, 300],
            [ '/floorguide?floorguide=3', 1800, 1900, 150, 200],
            [ '/floorguide?floorguide=2', 1800, 1900, 150, 200],
            [ '/search', 1500, 1600, 100, 150],
            [ '/search?category=2', 1400, 1500, 50, 100],
        ];

        $response = [];
        foreach ($pattern as $value){
            $response[] = [
                'path'     => $value[0],
                'view_min' => $value[1],
                'view_max' => $value[2],
                'user_min' => $value[3],
                'user_max' => $value[4],
            ];
        }

        return $response;
    }

    private function getDummyCount($min,$max){
        return random_int($min, $max);
    }

    private function storeAnalyticsPageView($response,$analytics_date)
    {
        // 保存するデータをセット
        $insert_data = [];
        $nowDateTime = date("Y-m-d H:i:s");
        foreach ($response as $value){
            $insert_data[] = array(
                'analytics_date' => $analytics_date,
                'page_path'      => $value['pagePath'],
                'pageviews'      => $value['screenPageViews'],
                'users'          => $value['activeUsers'],
                'created_at'     => $nowDateTime,
                'updated_at'     => $nowDateTime,
                );
        }

        // データベースへ保存
        AnalyticsPageViews::insert($insert_data);
    }

}