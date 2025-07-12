<div style="background: linear-gradient(145deg, #f6f8fb 0%, #f0f3f7 100%); padding: 40px 20px;">
    <div style="max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(32, 43, 54, 0.08);">
        <!-- Logo区域 -->
        <div style="padding: 35px 40px; text-align: center; border-bottom: 1px solid #f1f3f5;">
            <h1 style="margin: 0; font-size: 22px; color: #2c3345; font-weight: 600; letter-spacing: -0.5px;">{$config['appName']}</h1>
        </div>

        <!-- 内容区 -->
        <div style="padding: 40px;">
            <div style="text-align: center; margin-bottom: 35px;">
                <div style="width: 64px; height: 64px; background-color: #f0f3f7; border-radius: 50%; display: inline-block; line-height: 64px; font-size: 32px; color: #2c3345;">#</div>
                <h2 style="margin: 20px 0 0; font-size: 20px; color: #2c3345; font-weight: 600;">邮件发送测试</h2>
            </div>

            <div style="margin: 35px 0; text-align: center;">
                <p style="margin: 0; font-size: 14px; color: #2c3345; line-height: 1.6;">
                    这是一封来自 {$config['appName']} 的测试邮件。如果你能收到，说明邮件发送配置有效，可以正常工作。
                </p>
            </div>
        </div>

        <!-- 底部 -->
        <div style="padding: 20px; background-color: #f9fafb; text-align: center;">
            <p style="margin: 0; font-size: 13px; color: #8792a2;">
                安全邮件提醒 · {$config['appName']} | <a href="{$config['baseUrl']}/user/edit" style="color: #8792a2; text-decoration: none;">修改邮件接收设置</a>
            </p>
        </div>
    </div>
</div>