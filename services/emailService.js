const nodemailer = require('nodemailer');

const EMAIL_USER = process.env.EMAIL_USER || 'devhorizan@gmail.com';
const EMAIL_APP_PASSWORD = process.env.EMAIL_APP_PASSWORD || 'vffjemqekyefcpdn';

let transporter;
try {
  transporter = nodemailer.createTransport({
    service: 'gmail',
    auth: {
      user: EMAIL_USER,
      pass: EMAIL_APP_PASSWORD
    }
  });
} catch (err) {
  console.warn('⚠️ Nodemailer initialization warning:', err.message);
}

async function sendEmail({ to, subject, text, html, attachments = [] }) {
  if (!transporter) {
    console.log(`[Simulated Email] To: ${to} | Subject: ${subject}`);
    return { success: true, simulated: true };
  }

  try {
    const mailOptions = {
      from: `"DevHorizan Technologies" <${EMAIL_USER}>`,
      to,
      subject,
      text,
      html: html || `<pre style="font-family: Arial, sans-serif; white-space: pre-wrap;">${text}</pre>`,
      attachments
    };

    const info = await transporter.sendMail(mailOptions);
    console.log(`📧 Email sent to ${to}: ${info.messageId}`);
    return { success: true, messageId: info.messageId };
  } catch (error) {
    console.error('❌ Failed to send email via SMTP, logging fallback:', error.message);
    return { success: false, error: error.message };
  }
}

module.exports = {
  sendEmail,
  EMAIL_USER
};

