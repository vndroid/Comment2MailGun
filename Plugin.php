<?php

namespace TypechoPlugin\Comment2MailGun;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 评论回复通过 MailGun 发送邮件提醒
 *
 * @package Comment2MailGun
 * @author Vex
 * @version 1.2.0
 * @link https://github.com/vndroid/Comment2MailGun
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return string
     * @throws Exception
     */
    public static function activate(): string
    {
        if (!extension_loaded('curl')) {
            throw new Exception(_t('检测到当前 PHP 环境没有 cURL 组件, 无法正常使用此插件'));
        }
        if (!function_exists('curl_init')) {
            $disabled = ini_get('disable_functions');
            throw new Exception(_t('cURL 扩展已加载，但初始化方法不可用（可能被禁用）：') . ($disabled ?: 'unknown'));
        }
        Helper::addAction('Comment2MailGun', __NAMESPACE__ . '\Action');
        \Typecho\Plugin::factory('Widget_Feedback')->finishComment = array('Comment2MailGun_Plugin', 'toMail');

        return _t('请到设置面板正确配置 MailGun 令牌才可正常工作');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @access public
     * @return void
     */
    public static function deactivate(): void
    {
        Helper::removeAction('Comment2MailGun');
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form): void
    {
        $mail = new Text('mail', null, null,
                _t('收件人邮箱'),_t('接收邮件用的信箱，为空则使用文章作者个人设置中的默认邮箱'));
        $form->addInput($mail->addRule('email', _t('请填写正确的邮箱！')));

        $status = new Checkbox('status',
                array('approved' => '提醒已通过评论',
                        'waiting' => '提醒待审核评论',
                        'spam' => '提醒垃圾评论'),
                array('approved', 'waiting'), '提醒设置',_t('该选项仅针对博主，访客只发送已通过的评论。'));
        $form->addInput($status);

        $other = new Checkbox('other',
                array('to_owner' => '有评论及回复时，发邮件通知博主',
                    'to_guest' => '评论被回复时，发邮件通知评论者',
                    'to_me'=>'自己回复自己的评论时（同时针对博主和访客），发邮件通知',
                    'to_log' => '记录邮件发送日志'),
                array('to_owner','to_guest'), '其他设置',_t('如果勾选“记录邮件发送日志”选项，则会在插件根目录 logs/mail_log.php 中记录邮件发送信息。<br>
                    关键性错误日志将自动记录到 logs/error_log.php 中。'));
        $form->addInput($other->multiMode());

        $key = new Text('key', null, 'xxxxxxxxxxxxxxxxxxx-xxxxxx-xxxxxx',
                _t('MailGun API 密钥'), _t('请填写在<a href="https://mailgun.com/"> MailGun </a>申请的密钥，可在<a href="https://app.mailgun.com/app/account/security/api_keys">个人页</a>中查看 '));
        $form->addInput($key->addRule('required', _t('密钥不能为空')));

        $domain = new Text('domain', null, 'samples.mailgun.org',
                _t('MailGun 域名'), _t('请填写您的邮件域名，若使用官方提供的测试域名可能存在其他问题'));
        $form->addInput($domain->addRule('required', _t('邮件域名不能为空')));

        $mailAddress = new Text('mailAddress', null, 'no-reply@samples.mailgun.org',
                _t('发件人邮箱'));
        $form->addInput($mailAddress->addRule('required', _t('发件人地址不能为空')));

        $senderName = new Text('senderName', null, '评论提醒',
                _t('发件人显示名'));
        $form->addInput($senderName);

        $titleForOwner = new Text('titleForOwner',null,"[{site}]:《{title}》有新的评论",
                _t('博主接收邮件标题'));
        $form->addInput($titleForOwner);

        $titleForGuest = new Text('titleForGuest',null,"[{site}]:您在《{title}》的评论有了回复",
                _t('访客接收邮件标题'));
        $form->addInput($titleForGuest);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 组合邮件内容
     *
     * @access public
     * @param $post
     * @return void
     * @throws Exception
     * @throws \Typecho\Db\Exception
     */
    public static function toMail($post): void
    {
        //发送邮件
        $options = Helper::options();
        $settings = $options->plugin('Comment2MailGun');
        //邮件模板变量
        $tempInfo['site']          = $options->title;
        $tempInfo['siteUrl']       = $options->siteUrl;
        $tempInfo['title']         = $post->title;
        $tempInfo['cid']           = $post->cid;
        $tempInfo['coid']          = $post->coid;
        $tempInfo['created']       = $post->created;
        $tempInfo['timezone']      = $options->timezone;
        $tempInfo['author']        = $post->author;
        $tempInfo['authorId']      = $post->authorId;
        $tempInfo['ownerId']       = $post->ownerId;
        $tempInfo['mail']          = $post->mail;
        $tempInfo['ip']            = $post->ip;
        $tempInfo['text']          = $post->text;
        $tempInfo['permalink']     = $post->permalink;
        $tempInfo['status']        = $post->status;
        $tempInfo['parent']        = $post->parent;
        $tempInfo['manage']        = $options->siteUrl . "admin/manage-comments.php";
        $tempInfo['currentYear']   = date('Y');
        $db = \Typecho\Db::get();
        $original = $db->fetchRow($db->select('author', 'mail', 'text')
                    ->from('table.comments')
                    ->where('coid = ?', $tempInfo['parent']));
        //var_dump($original);die();

        //判断发送
        //1.发送博主邮件
        //无需判断，先发为敬。
        if(in_array('to_owner', $settings->other) && in_array($tempInfo['status'], $settings->status)){
            $this_mail = $tempInfo['mail'];
            $to_mail = $settings->mail;
            if (!$to_mail) {
                $user = \Widget\Users\Author::allocWithAlias((string) $tempInfo['cid'], ['uid' => $tempInfo['authorId']]);
                $to_mail = $user->mail;
            }
            if($this_mail != $to_mail || in_array('to_me',$settings->other)){
                //判定可以发送邮件
                $from_mail = $settings->mailAddress;
                $title = self::_getTitle(false,$settings,$tempInfo);
                $body = self::_getHtml(false,$tempInfo);
                self::_sendMail($to_mail,$from_mail,$title,$body,$settings);
            }
        }
        //2.发送评论者邮件
        //判断是否为回复评论，是则发，否则跳。
        if (!empty($original)){
            $tempInfo['originalMail'] = $original['mail'];
            $tempInfo['originalText'] = $original['text'];
            $tempInfo['originalAuthor'] = $original['author'];
            if(in_array('to_guest', $settings->other) && 'approved'==$tempInfo['status'] && $tempInfo['originalMail']){
                $to_mail = $tempInfo['originalMail'];
                $from_mail = $settings->mailAddress;
                $title = self::_getTitle(true,$settings,$tempInfo);
                $body = self::_getHtml(true,$tempInfo);
                self::_sendMail($to_mail,$from_mail,$title,$body,$settings);
            }
        }

    }

    /**
     * 获取邮件标题
     *
     * @access public
     * @param bool $toGuest
     * @param $settings
     * @param array $tempInfo
     * @return string
     */
    public static function _getTitle(bool $toGuest, $settings, array $tempInfo): string
    {
        $title = (string)($toGuest ? $settings->titleForGuest : $settings->titleForOwner);
        return str_replace(['{title}', '{site}'], [$tempInfo['title'], $tempInfo['site']], $title);
    }

    /**
     * 获取一言随机名言
     *
     * @access public
     * @return array{hitokoto: string, from: string}
     */
    public static function _hitokoto(): array
    {
        $url = 'https://international.v1.hitokoto.cn/';
        $yy = curl_init();
        curl_setopt($yy, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($yy, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($yy, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($yy, CURLOPT_URL, $url);
        $result = curl_exec($yy);
        curl_close($yy);

        $fallback = ['hitokoto' => '', 'from' => ''];

        if (!is_string($result) || empty($result)) {
            return $fallback;
        }

        $yiyan = json_decode($result, true);
        if (!is_array($yiyan) || empty($yiyan['hitokoto'])) {
            return $fallback;
        }

        return [
            'hitokoto' => (string)$yiyan['hitokoto'],
            'from'     => (string)($yiyan['from'] ?? ''),
        ];
    }

    /**
     * 匹配邮件模板并替换变量
     *
     * @access public
     * @param bool $toGuest
     * @param array $tempInfo
     * @return string
     */
    public static function _getHtml(bool $toGuest, array $tempInfo): string
    {
        //获取发送模板
        $dir = dirname(__FILE__) . '/';
        $time = date("Y-m-d H:i:s", $tempInfo['created'] + $tempInfo['timezone']);
        if ($toGuest) {
            $dir .= 'guest.html';
            $yiyan = self::_hitokoto();
            $search = array('{site}', '{siteUrl}', '{title}', '{originAuthor}', '{author}', '{mail}', '{permaLink}', '{repyComment}', '{myComment}', '{currentYear}', '{time}', '{yiyanBody}', '{yiyanFrom}');
            $replace = array($tempInfo['site'], $tempInfo['siteUrl'], $tempInfo['title'], $tempInfo['originalAuthor'], $tempInfo['author'], $tempInfo['mail'], $tempInfo['permalink'], $tempInfo['text'], $tempInfo['originalText'], $tempInfo['currentYear'], $time, $yiyan['hitokoto'], $yiyan['from']);
        } else {
            $dir .= 'owner.html';
            $status = array(
                "approved" => '通过',
                "waiting" => '待审',
                "spam" => '垃圾'
            );
            $search = array('{site}', '{siteUrl}', '{title}', '{author}', '{ip}', '{mail}', '{permaLink}', '{manage}', '{comment}', '{currentYear}', '{time}', '{status}');
            $replace = array($tempInfo['site'], $tempInfo['siteUrl'], $tempInfo['title'], $tempInfo['author'], $tempInfo['ip'], $tempInfo['mail'], $tempInfo['permalink'], $tempInfo['manage'], $tempInfo['text'], $tempInfo['currentYear'], $time, $status[$tempInfo['status']]);
        }
        $html = file_get_contents($dir);
        if ($html === false) {
            return '';
        }
        return (string)str_replace($search, $replace, $html);
    }

    /**
     * 邮件发送方法
     *
     * @access public
     * @throws Exception
     */
    public static function _sendMail($to_mail, $from_mail, $title, $body, $settings): void
    {
        //self::_log($to_mail,'debug');return;
        $api_key = $settings->key;
        $domain = $settings->domain;
        $from_mail = $settings->senderName . ' <' . $from_mail . '>';
        $postData = array(
            'from' => $from_mail,
            'to' => $to_mail,
            'subject' => $title,
            'html' => $body,
        );
        $url = 'https://api.mailgun.net/v3/' . $domain . '/messages';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $api_key);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HEADER, true);
        self::_log('curl preparing...' . print_r(curl_getinfo($ch), 1), 'debug');
        $result = curl_exec($ch);
        self::_log('API return...' . $result, 'debug');
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $result = substr($result, $headerSize);
        $res = json_decode($result, 1);
        self::_log('curl executed...' . print_r(curl_getinfo($ch), 1), 'debug');
        self::_log($to_mail . ' ' . 'Sending: ' . $res['message']);
    }
    public static function _log($msg,$file='error'){
        //记录日志
        $settings=Helper::options()->plugin('Comment2MailGun');
        if(!in_array('to_log', $settings->other)) return false;
        //开发者模式
        if($file=='debug' && true) return false;
        $log_dir = dirname(__FILE__) . '/logs';
        $filename = $log_dir . '/' . $file . '_log.php';
        //检查日志目录是否存在且可写，不存在则使用临时目录
        if (!is_dir($log_dir) || !is_writable($log_dir)) {
            $filename = '/tmp/mailgun_' . $file . '.log';
        }
        // 仅 logs 目录下的 .php 文件写入 PHP 头
        if (strpos($filename, '_log.php') !== false && !is_file($filename)) {
            file_put_contents($filename, "<?php \$log = <<<LOG\n");
        }

        $log = @fopen($filename, 'a');
        if ($log) {
            fwrite($log, date('[Y-m-d H:i:s]') . ' ' . $msg . PHP_EOL);
            fclose($log);
            return true;
        }
        return false;
    }
}
