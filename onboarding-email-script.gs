/**
 * Envision Client Onboarding :: Google Apps Script web app
 *
 * Receives a submission from the onboarding wizard and emails a formatted
 * summary to the team. Setup steps are in ONBOARDING-README.md.
 *
 * The recipient is fixed here on purpose. The wizard cannot change who
 * receives email, so this URL can't be used to send mail to anyone else.
 */
var TO_EMAIL = 'team@envisionmktg.com';
var SENDER_NAME = 'Envision Onboarding';
var MAX_BYTES = 60000; // ignore oversized posts

function doPost(e) {
  try {
    var raw = (e && e.postData && e.postData.contents) || '';
    if (!raw || raw.length > MAX_BYTES) return reply_({ ok: false, error: 'empty or too large' });
    var data = JSON.parse(raw);
    if (data.source !== 'envision-onboarding') return reply_({ ok: false, error: 'unknown source' });

    var biz = data.business || {};
    var client = data.client || {};
    var access = Array.isArray(data.access) ? data.access : [];
    var name = clean_(client.name || biz.company || 'Unknown client');
    var done = access.filter(function (a) { return a && a.done; }).length;

    var options = {
      name: SENDER_NAME,
      htmlBody: buildHtml_(data, name, done, access.length),
    };
    var replyTo = clean_(biz.email || client.email || '');
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(replyTo)) options.replyTo = replyTo;

    MailApp.sendEmail(
      TO_EMAIL,
      'Onboarding submitted: ' + name + ' (' + done + ' of ' + access.length + ' access steps done)',
      String(data.summaryText || '').slice(0, 20000) || 'See the HTML version of this email.',
      options
    );
    return reply_({ ok: true });
  } catch (err) {
    return reply_({ ok: false, error: String(err) });
  }
}

/** Visiting the URL in a browser shows this, which confirms the deployment works. */
function doGet() {
  return reply_({ ok: true, service: 'envision-onboarding', to: TO_EMAIL });
}

function buildHtml_(data, name, done, total) {
  var biz = data.business || {};
  var labels = {
    company: 'Business name', website: 'Website', phone: 'Main phone', address: 'Address',
    industry: 'Industry', areas: 'Service areas', contact: 'Primary contact', email: 'Contact email',
    cphone: 'Contact phone', goals: '90-day goals'
  };
  var h = [];
  h.push('<div style="font-family:Arial,Helvetica,sans-serif;color:#0D0D0D;max-width:680px">');
  h.push('<h2 style="margin:0 0 4px;color:#0A6EB4">New onboarding: ' + esc_(name) + '</h2>');
  h.push('<p style="margin:0 0 16px;color:#4A4A4A">' + done + ' of ' + total + ' access steps marked done' +
         (data.submittedAt ? ' &middot; submitted ' + esc_(formatDate_(data.submittedAt)) : '') + '</p>');
  if (data.services && data.services.length) {
    h.push('<p style="margin:0 0 16px"><b>Services:</b> ' + esc_(data.services.join(', ')) + '</p>');
  }

  h.push(section_('Business'));
  h.push('<table style="border-collapse:collapse;width:100%;font-size:14px">');
  Object.keys(labels).forEach(function (k) {
    if (biz[k]) h.push(row_(labels[k], biz[k]));
  });
  h.push('</table>');

  (data.access || []).forEach(function (a) {
    if (!a) return;
    var badge = a.done
      ? '<span style="background:#0EA371;color:#fff;border-radius:10px;padding:2px 8px;font-size:12px">Done</span>'
      : '<span style="background:#C47A00;color:#fff;border-radius:10px;padding:2px 8px;font-size:12px">Not done</span>';
    h.push(section_(esc_(a.module) + ' ' + badge, true));
    h.push('<table style="border-collapse:collapse;width:100%;font-size:14px">');
    var any = false;
    Object.keys(a).forEach(function (k) {
      if (k === 'module' || k === 'done' || k === 'blocker') return;
      h.push(row_(k, a[k])); any = true;
    });
    if (a.blocker) { h.push(row_('Blocker / question', a.blocker, '#C0392B')); any = true; }
    if (!any) h.push('<tr><td style="padding:6px 0;color:#7A8794">No details entered.</td></tr>');
    h.push('</table>');
  });
  h.push('<p style="margin:24px 0 0;color:#7A8794;font-size:12px">Reply to this email to reach the client directly.</p>');
  h.push('</div>');
  return h.join('');
}

function section_(title, raw) {
  return '<h3 style="margin:22px 0 6px;padding-bottom:6px;border-bottom:1px solid #E6ECF3;font-size:15px">' +
         (raw ? title : esc_(title)) + '</h3>';
}

function row_(label, value, color) {
  return '<tr><td style="padding:6px 12px 6px 0;color:#7A8794;vertical-align:top;width:40%">' + esc_(label) +
         '</td><td style="padding:6px 0;vertical-align:top;white-space:pre-wrap' + (color ? ';color:' + color : '') + '">' +
         esc_(value) + '</td></tr>';
}

function formatDate_(iso) {
  var d = new Date(iso);
  if (isNaN(d.getTime())) return String(iso);
  return Utilities.formatDate(d, Session.getScriptTimeZone(), 'MMM d, yyyy h:mm a');
}

function clean_(s) { return String(s == null ? '' : s).replace(/[\r\n]+/g, ' ').trim().slice(0, 200); }

function esc_(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

function reply_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
