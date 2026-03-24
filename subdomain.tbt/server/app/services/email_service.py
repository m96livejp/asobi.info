import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from email.utils import formataddr

from app.config import settings


def send_verification_email(to_email: str, verify_url: str) -> None:
    """メール認証用のメールを送信"""
    msg = MIMEMultipart("alternative")
    msg["Subject"] = "【Tournament Battle】メールアドレスの確認"
    msg["From"] = formataddr((settings.SMTP_FROM_NAME, settings.SMTP_FROM))
    msg["To"] = to_email

    frontend_url = settings.FRONTEND_URL

    text = (
        "Tournament Battle をご利用いただきありがとうございます。\n\n"
        "以下のリンクをクリックしてメールアドレスを認証してください。\n"
        "このリンクは24時間有効です。\n\n"
        f"{verify_url}\n\n"
        f"認証後はこちらからアクセスできます: {frontend_url}\n\n"
        "このメールに心当たりがない場合は無視してください。\n"
    )

    html = f"""\
    <html>
    <body style="font-family: sans-serif; background: #f5f5f5; padding: 20px;">
      <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h2 style="color: #2e3d2f; margin-top: 0;">Tournament Battle</h2>
        <p>ご利用いただきありがとうございます。</p>
        <p>以下のボタンをクリックしてメールアドレスを認証してください。</p>
        <div style="text-align: center; margin: 24px 0;">
          <a href="{verify_url}"
             style="display: inline-block; background: #4CAF50; color: #fff; padding: 14px 32px;
                    border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 16px;">
            メールアドレスを認証する
          </a>
        </div>
        <p style="color: #888; font-size: 13px;">このリンクは24時間有効です。</p>
        <p style="color: #888; font-size: 13px;">ボタンが押せない場合は以下のURLをブラウザに貼り付けてください:</p>
        <p style="color: #888; font-size: 12px; word-break: break-all;">{verify_url}</p>
        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
        <p style="font-size: 13px;">認証後は <a href="{frontend_url}" style="color: #4CAF50;">{frontend_url}</a> からアクセスしてください。</p>
        <p style="color: #aaa; font-size: 11px;">このメールに心当たりがない場合は無視してください。</p>
      </div>
    </body>
    </html>
    """

    msg.attach(MIMEText(text, "plain", "utf-8"))
    msg.attach(MIMEText(html, "html", "utf-8"))

    with smtplib.SMTP(settings.SMTP_HOST, settings.SMTP_PORT) as server:
        server.starttls()
        server.login(settings.SMTP_USER, settings.SMTP_PASSWORD)
        server.sendmail(settings.SMTP_FROM, to_email, msg.as_string())
