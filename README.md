# Comment2MailGun

## 评论邮件通知插件

博客评论邮件提醒插件 for Typecho

- 基于 PHP 扩展 cURL 实现
- 基于 MailGun API 实现

## 通知范围

插件只监听 Typecho 前台评论提交流程，包括访客评论，以及登录用户在文章页面提交的评论或回复。

Typecho 后台“管理评论”页面中的回复不在本插件的通知范围内，这是插件的设计行为，不是发送故障。

## 发送方式

在 PHP-FPM/FastCGI 环境中，插件会先完成评论响应，再通过 `fastcgi_finish_request()` 延迟发送邮件，访客无需等待 MailGun 请求完成。

这种方式不依赖常驻队列进程，但发送期间仍会占用当前 PHP-FPM Worker；在不支持 `fastcgi_finish_request()` 的环境中，会退化为请求结束阶段同步执行。

## 发信限流（可选，依赖 Redis）

访客填写的邮箱未经验证：任何人都能冒用他人邮箱发评论，再回复这条评论，借本站的 MailGun 域名向该邮箱投递内容，或者用大量不同邮箱消耗 MailGun 配额。

在插件设置中启用「Redis 发信限流」后，以下三项限制由 Redis 原子判定，以**实际发送结果**为准（发送失败 1 分钟后允许重试），并发请求不会越过额度：

- 同一邮箱通知间隔：同一邮箱在该时间内最多收到一封回复通知（默认 600 秒）
- 全站每小时发信上限：含博主通知与访客通知（默认 200）
- 单个来源每小时访客通知上限：访客按 IP、登录用户按账号计（默认 20）

代价与注意事项：

- 需要可访问的 Redis 与 PHP redis 扩展；Redis 连接信息在本插件中单独填写，键名前缀为 `plugin:comment2mailgun:{站点指纹}:`，可与其他插件、站点共用一个库，所有键都带 TTL，邮箱只以哈希形式出现
- 启用后若 Redis 不可用，**访客回复通知暂停发送**并记录错误，博主通知照常发送
- Redis 重启或按内存策略淘汰键时，限流会短暂放宽

**不启用 Redis 时，上述三项限流全部不生效**，只保留「被回复的评论必须已通过审核」这一项检查，冒用邮箱刷邮件与配额消耗无法被严格限制。此时请至少保持 Typecho「评论设置」中的提交间隔与反垃圾保护开启。

## 兼容性

- PHP 8.2+
- Typecho 1.2+
- 可选：PHP redis 扩展 + Redis（启用发信限流时）

### 安装方法

进入插件目录，克隆插件代码：

```bash
git clone https://github.com/vndroid/Comment2MailGun.git
```

在后台启用即可

### 适用环境

插件发送邮件利用 PHP 原生扩展 cURL 连接 MailGun 的 HTTPS 接口，具有到达率高，延迟低等特点，适合于无法使用 SMTP 的环境使用。

若当前 PHP 环境无法修改以支持 cURL 组件，可以尝试使用 [CommentToMail](http://docs.typecho.org/plugins/commenttomail) 插件，然后参照 [MailGun User Manual](https://documentation.mailgun.com/en/latest/user_manual.html#introduction) 上的相关说明设置 SMTP 服务。

经测试，SAE 可用。

### 感谢

[@三三](https://github.com/oott123)

### 其它问题

有问题请发 issues ，欢迎提交代码。其他 MailGun 说明可在[官方文档](https://documentation.mailgun.com/en/latest/)中查看。
