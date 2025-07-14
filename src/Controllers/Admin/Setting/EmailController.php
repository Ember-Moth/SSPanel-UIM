<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Setting;

use App\Controllers\BaseController;
use App\Models\Config;
use App\Services\Queue;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Smarty\Exception;

final class EmailController extends BaseController
{
    private array $update_field;
    private array $settings;

    public function __construct()
    {
        parent::__construct();
        $this->update_field = Config::getItemListByClass('email');
        $this->settings = Config::getClass('email');
    }

    /**
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write(
            $this->view()
                ->assign('update_field', $this->update_field)
                ->assign('settings', $this->settings)
                ->fetch('admin/setting/email.tpl')
        );
    }

    public function save(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        foreach ($this->update_field as $item) {
            if (! Config::set($item, $request->getParam($item))) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '保存 ' . $item . ' 时出错',
                ]);
            }
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '保存成功',
        ]);
    }

    public function testEmail(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $to = $request->getParam('recipient');

        //邮件发送的参数
        $emailData = [
            'to_email' => $to,
            'subject'  => '测试邮件',
            'template' => 'test.tpl',
        ];

        //使用队列添加任务
        $emailQueue = new Queue('email_queue');
        $emailQueue->add($emailData, 'email');
        return $response->withJson([
            'ret' => 1,
            'msg' => '测试邮件发送任务已成功加入队列',
        ]);
    }
}
