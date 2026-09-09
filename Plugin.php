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
 * @version 1.3.0
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
        // 清理旧版本曾注册的无效 Action 路由
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
                    关键性错误日志将自动记录到 logs/error_log.php 中；插件目录只读时将降级到系统临时目录。'));
        $form->addInput($other->multiMode());

        $key = new Text('key', null, 'xxxxxxxxxxxxxxxxxxx-xxxxxx-xxxxxx',
                _t('MailGun API 密钥'), _t('请填写在<a href="https://mailgun.com/"> MailGun </a>申请的密钥，可在<a href="https://app.mailgun.com/app/account/security/api_keys">个人页</a>中查看 '));
        $form->addInput($key->addRule('required', _t('密钥不能为空')));

        $domain = new Text('domain', null, 'samples.mailgun.org',
                _t('MailGun 域名'), _t('请填写您的邮件域名，若使用官方提供的测试域名可能存在其他问题'));
        $form->addInput($domain
                ->addRule('required', _t('邮件域名不能为空'))
                ->addRule(
                    'regexp',
                    _t('请填写正确的邮件域名，例如 mg.example.com'),
                    '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i'
                ));

        $mailAddress = new Text('mailAddress', null, 'no-reply@samples.mailgun.org',
                _t('发件人邮箱'));
        $form->addInput($mailAddress
                ->addRule('required', _t('发件人地址不能为空'))
                ->addRule('email', _t('请填写正确的发件人邮箱')));

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
                    ->where('coid = ? AND cid = ?', $tempInfo['parent'], $tempInfo['cid']));
        //var_dump($original);die();

        //判断发送
        //1.发送博主邮件
        //无需判断，先发为敬。
        if(in_array('to_owner', $settings->other) && in_array($tempInfo['status'], $settings->status)){
            $this_mail = $tempInfo['mail'];
            $to_mail = $settings->mail;
            if (!$to_mail) {
                $user = \Widget\Users\Author::allocWithAlias(
                    'comment2mailgun_owner_' . $tempInfo['ownerId'],
                    ['uid' => $tempInfo['ownerId']]
                );
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
        $title = str_replace(['{title}', '{site}'], [$tempInfo['title'], $tempInfo['site']], $title);
        return trim(str_replace(["\r", "\n"], ' ', $title));
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
        curl_setopt_array($yy, [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_URL => $url,
        ]);
        $result = curl_exec($yy);
        $statusCode = (int)curl_getinfo($yy, CURLINFO_RESPONSE_CODE);
        curl_close($yy);

        $fallback = ['hitokoto' => '', 'from' => ''];

        if (!is_string($result) || empty($result) || $statusCode < 200 || $statusCode >= 300) {
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
            $replace = array(
                self::_escapeHtml($tempInfo['site']),
                self::_escapeUrl($tempInfo['siteUrl']),
                self::_escapeHtml($tempInfo['title']),
                self::_escapeHtml($tempInfo['originalAuthor']),
                self::_escapeHtml($tempInfo['author']),
                self::_escapeEmail($tempInfo['mail']),
                self::_escapeUrl($tempInfo['permalink']),
                self::_escapeText($tempInfo['text']),
                self::_escapeText($tempInfo['originalText']),
                self::_escapeHtml($tempInfo['currentYear']),
                self::_escapeHtml($time),
                self::_escapeHtml($yiyan['hitokoto']),
                self::_escapeHtml($yiyan['from']),
            );
        } else {
            $dir .= 'owner.html';
            $status = array(
                "approved" => '通过',
                "waiting" => '待审',
                "spam" => '垃圾'
            );
            $search = array('{site}', '{siteUrl}', '{title}', '{author}', '{ip}', '{mail}', '{permaLink}', '{manage}', '{comment}', '{currentYear}', '{time}', '{status}');
            $replace = array(
                self::_escapeHtml($tempInfo['site']),
                self::_escapeUrl($tempInfo['siteUrl']),
                self::_escapeHtml($tempInfo['title']),
                self::_escapeHtml($tempInfo['author']),
                self::_escapeHtml($tempInfo['ip']),
                self::_escapeEmail($tempInfo['mail']),
                self::_escapeUrl($tempInfo['permalink']),
                self::_escapeUrl($tempInfo['manage']),
                self::_escapeText($tempInfo['text']),
                self::_escapeHtml($tempInfo['currentYear']),
                self::_escapeHtml($time),
                self::_escapeHtml($status[$tempInfo['status']]),
            );
        }
        $html = file_get_contents($dir);
        if ($html === false) {
            return '';
        }
        return (string)str_replace($search, $replace, $html);
    }

    /**
     * 转义 HTML 文本节点
     */
    private static function _escapeHtml($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 转义多行纯文本并保留换行
     */
    private static function _escapeText($value): string
    {
        return nl2br(self::_escapeHtml($value), false);
    }

    /**
     * 校验并转义 HTML 链接
     */
    private static function _escapeUrl($value): string
    {
        $url = trim((string)$value);
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            return '#';
        }

        return self::_escapeHtml($url);
    }

    /**
     * 校验并转义邮件地址
     */
    private static function _escapeEmail($value): string
    {
        $mail = trim((string)$value);
        return filter_var($mail, FILTER_VALIDATE_EMAIL) === false ? '' : self::_escapeHtml($mail);
    }

    /**
     * 邮件发送方法
     *
     * @access public
     * @return bool
     */
    public static function _sendMail($to_mail, $from_mail, $title, $body, $settings): bool
    {
        // self::_log($to_mail, 'debug'); return true;
        $apiKey = (string)$settings->key;
        $domain = trim((string)$settings->domain);
        $toMail = trim((string)$to_mail);
        $fromMail = trim((string)$from_mail);

        if (
            filter_var($toMail, FILTER_VALIDATE_EMAIL) === false
            || filter_var($fromMail, FILTER_VALIDATE_EMAIL) === false
            || !preg_match(
                '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
                $domain
            )
        ) {
            self::_log('邮件发送失败：收件人、发件人或 MailGun 域名格式错误');
            return false;
        }

        $senderName = trim(str_replace(["\r", "\n"], '', (string)$settings->senderName));
        $senderName = str_replace(['\\', '"'], ['\\\\', '\\"'], $senderName);
        $from = '"' . $senderName . '" <' . $fromMail . '>';
        $postData = array(
            'from' => $from,
            'to' => $toMail,
            'subject' => (string)$title,
            'html' => (string)$body,
        );
        $url = 'https://api.mailgun.net/v3/' . rawurlencode($domain) . '/messages';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => 'api:' . $apiKey,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($result)) {
            self::_log('邮件发送失败：网络请求错误（' . $curlError . '）');
            return false;
        }

        $response = json_decode($result, true);
        $message = is_array($response) && isset($response['message'])
            ? (string)$response['message']
            : 'MailGun 未返回有效消息';

        if ($statusCode < 200 || $statusCode >= 300) {
            self::_log('邮件发送失败：MailGun HTTP ' . $statusCode . '（' . $message . '）');
            return false;
        }

        self::_log($toMail . ' Sending: ' . $message, 'mail');
        return true;
    }

    /**
     * 日志记录方法
     *
     * @param $msg
     * @param string $file
     * @return bool
     * @throws Exception
     */
    public static function _log($msg, string $file = 'error'): bool
    {
        $settings = Helper::options()->plugin('Comment2MailGun');
        if (!in_array('to_log', (array)$settings->other, true)) {
            return false;
        }

        if (!in_array($file, ['mail', 'error', 'debug'], true)) {
            return false;
        }

        $log_dir = dirname(__FILE__) . '/logs';
        if (!is_dir($log_dir) || !is_writable($log_dir)) {
            $log_dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'comment2mailgun-'
                . substr(hash('sha256', __DIR__ . ':' . (string)getmyuid()), 0, 16);

            if (is_link($log_dir)) {
                return false;
            }

            if (!is_dir($log_dir) && !@mkdir($log_dir, 0700) && !is_dir($log_dir)) {
                return false;
            }

            @chmod($log_dir, 0700);
            if (!is_writable($log_dir)) {
                return false;
            }
        }

        $filename = $log_dir . '/' . $file . '_log.php';
        if (is_link($filename)) {
            return false;
        }

        $log = @fopen($filename, 'c+');
        if (!$log || !flock($log, LOCK_EX)) {
            if ($log) {
                fclose($log);
            }
            return false;
        }

        $safeHeader = "<?php exit; __halt_compiler(); ?>\n";
        rewind($log);
        $prefix = fread($log, strlen($safeHeader));
        if ($prefix !== $safeHeader) {
            rewind($log);
            $existing = stream_get_contents($log);
            ftruncate($log, 0);
            rewind($log);
            fwrite($log, $safeHeader . $existing);
        }

        $message = str_replace(["\r", "\n"], ['\\r', '\\n'], (string)$msg);
        fseek($log, 0, SEEK_END);
        $written = fwrite($log, date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL) !== false;
        fflush($log);
        flock($log, LOCK_UN);
        fclose($log);

        return $written;
    }
}
