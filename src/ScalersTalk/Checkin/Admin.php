<?php
/**
 * @Author: AminBy
 * @Date:   2016-10-16 16:50:10
 * @Last Modified by:   AminBy
 * @Last Modified time: 2016-10-30 01:50:23
 */
namespace ScalersTalk\Checkin;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \Slim\Views\PhpRenderer;
use \LeanCloud\Client;
use \LeanCloud\Storage\CookieStorage;
use \LeanCloud\Engine\SlimEngine;

use \ScalersTalk\Data\Checkin as DataCheckin;
use \ScalersTalk\Data\Leave as DataLeave;
use \ScalersTalk\Data\QQUser as DataQQUser;
use \ScalersTalk\Data\Config as DataConfig;
use \ScalersTalk\Util\ChatParser;
use \ScalersTalk\Setting\Items;
use \ScalersTalk\Setting\Config;

class Admin extends CheckinBase {

    public function showUpload(Request $req, Response $resp, $args) {
        $this->app->view['groups'] = Config::get('groups');
        return $this->app->view->render($resp, "upload.twig", $args);
    }

    public function upload(Request $req, Response $resp, $args) {
        $this->setLastUpdatedForView($args['group']);

        $groups = Config::get('groups');

        // 获取上传的文件
        $files = $req->getUploadedFiles();
        if(empty($files['qqchat'])) {
            die('file qqchat is empty!');
        }
        if(empty($args['group']) || !in_array($args['group'], array_keys($groups))) {
            die('illegal group value!');
        }
        $tmpfile = $files['qqchat']->file;

        // dataConfig
        $dataConfig = new DataConfig($args['group']);
        $lastUpdated = $dataConfig->get('lastUpdated', 0);

        // 解析上传的文件
        $chatParser = new ChatParser($tmpfile, Items::get($args['group']), $lastUpdated);
        $chatParser->parse();

        // 保存
        $dataCheckin = new DataCheckin($args['group']);
        $dataLeave = new DataLeave($args['group']);
        $dataQQuser = new DataQQUser($args['group']);

        $dataLeave->batch_save($chatParser->leaves);
        $dataCheckin->batch_save($chatParser->checkins);
        $dataQQuser->batch_save($chatParser->getQqusers());

        $dataConfig->set('lastUpdated', (string)($chatParser->getCurrentUpdate()));

        // 跳转到看最近一周的数据
        return $resp->withStatus(302)->withHeader('Location', $this->app->router->pathFor('admin-view', $args));
    }

    // 最近一周的数据
    public function viewAll(Request $req, Response $resp, $args) {
        $this->setLastUpdatedForView($args['group']);

        $dataCheckin = new DataCheckin($args['group']);
        $dataLeave = new DataLeave($args['group']);
        $dataQQUser = new DataQQUser($args['group']);

        $_qqusers = DataQQUser::asArray($dataQQUser->all());
        if(empty($_qqusers)) {
            die('qq users is empty');
        }

        // 起止时间
        $query = $req->getQueryParams();
        if(empty($query['dateRange'])) {
            $start = "sun 1 week ago";
            $end = "last sat";
        }
        else {
            list($start, $end) = explode(' to ', $query['dateRange']);
        }
        $start = strtotime($start);
        $end = strtotime($end);

        $args += compact('start', 'end');

        // 获取数据
        $_checkins = DataCheckin::asArray($dataCheckin->allWithDate($start, $end));
        $_checkins = \array_group_by($_checkins, 'qqno', 'date');

        $_leaves = DataLeave::asArray($dataLeave->allWithDate($start, $end));
        $_leaves = \array_group_by($_leaves, 'qqno', 'date');

        $_qqusers = array_column($_qqusers, 'nick', 'qqno');

        // 排序
        uksort($_qqusers, function($a, $b) use($_checkins, $_leaves) {
            $vac = empty($_checkins[$a]) ? 0 : array_sum(array_map(function($v) {
                return count($v);
            }, $_checkins[$a]));
            $vbc = empty($_checkins[$b]) ? 0 : array_sum(array_map(function($v) {
                return count($v);
            }, $_checkins[$b]));
            $val = empty($_leaves[$a]) ? 0 : count($_leaves[$a]);
            $vbl = empty($_leaves[$b]) ? 0 : count($_leaves[$b]);

            if($vac == $vbc) {
                return $val > $vbl ? -1 : 1;
            };
            return $vac > $vbc ? -1 : 1;
        });

        // 绑定数据
        $args['_range'] = range($start, $end, 86400);
        $args['_checkins'] = $_checkins;
        $args['_leaves'] = $_leaves;
        $args['_qqusers'] = $_qqusers;

        return $this->app->view->render($resp, "all-records.twig", $args);
    }

    public function viewStatistics(Request $req, Response $resp, $args) {
        $this->setLastUpdatedForView($args['group']);

        $dataCheckin = new DataCheckin($args['group']);
        $dataLeave = new DataLeave($args['group']);
        $dataQQUser = new DataQQUser($args['group']);

        // 起止时间
        $end = strtotime('last sat');
        $start = strtotime('-5 weeks +1 day', $end);

        // 项目
        $items = Items::get($args['group']) + [
            'leave' => [
                'name' => '请假',
                'valid' => false,
                ]
            ];
        $itemkeys = array_keys($items);
        $_qqusers = DataQQUser::asArray($dataQQUser->all());
        $_qqnos = array_column($_qqusers, 'qqno');

        // 要统计的数据, _statistics1是各项目的数据, _statistics2是每周的数据
        $_statistics1 = array_combine($_qqnos, array_fill(0, count($_qqnos), array_combine($itemkeys, array_fill(0, count($itemkeys), 0))));
        $_statistics2 = array_combine($_qqnos, array_fill(0, count($_qqnos), array_combine(array_reverse(range($start, $end, 604800)), array_fill(0, 5, 0))));

        // 获得数据
        $_leaves = $dataLeave->allWithDate($start, $end);
        $_checkins = $dataCheckin->allWithDate($start, $end);

        // 统计
        array_map(function($obj) use(&$_statistics1, &$_statistics2) {
            if($obj->get('isvalid')) {
                $_statistics1[$obj->get('qqno')][$obj->get('itemkey')] += 1;
            }
            if($obj->get('itemkey') != 'leave') {
                $_statistics2[$obj->get('qqno')][strtotime('last sun', $obj->get('date'))] += 1;
            }
        }, array_merge($_leaves, $_checkins));

        // 排序
        uasort($_statistics2, function($a, $b) {
            $sa = array_sum($a);
            $sb = array_sum($b);
            if($sa == $sb) {
                return 0;
            }
            return $sa < $sb ? 1 : -1;
        });

        // 绑定数据
        $args['_items'] = $items;
        $args['_statistics1'] = $_statistics1;
        $args['_statistics2'] = $_statistics2;
        $args['_qqusers'] = array_column($_qqusers, 'nick', 'qqno');
        $args['_start'] = $start;
        $args['_end'] = $end;

        return $this->app->view->render($resp, "statistics.twig", $args);
    }

    public function showAdmin(Request $req, Response $resp, $args) {
        $args['groups'] = Config::get('groups');
        return $this->app->view->render($resp, "admin.twig", $args);
    }
}