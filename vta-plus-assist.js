/* ChatGPT Plus companion: manual clipboard workflow, not an OpenAI API integration. */
(function () {
  'use strict';
  const CHATGPT_URL = 'https://chatgpt.com/';
  const prompts = Object.freeze({
    itinerary: [
      'Act as the senior Vietnam inbound tour designer for Vietnam Travel Advisor (VTA).',
      'Help improve the itinerary that I paste below. Keep the original trip duration and day/night count.',
      'Check driving times, sightseeing load, overnight locations, meal statements and realistic routes.',
      'Return a polished, editable English itinerary with Day 1, Day 2, etc.',
      'Do not invent flight schedules, confirmed services, availability, supplier prices or inclusions.',
      'Flag anything requiring verification. Preserve all stated requirements.',
      '',
      'ITINERARY TO REVIEW (paste a version without private guest or supplier information):',
      '[Paste itinerary here]'
    ].join('\n'),
    sales: [
      'Act as a senior inbound Vietnam DMC Sales & B2B Account Manager at Vietnam Travel Advisor (VTA).',
      'Help prepare a professional reply and a clear travel proposal for the inquiry below.',
      'Ask about any missing travel dates, paying guests, FOC, hotel/cruise levels, meals and Private/SIC preference.',
      'Write a concise agent-ready response in professional English, including next steps.',
      'Do not invent prices, confirm bookings or promise availability. Mark unverified items.',
      'Any final quotation must be checked against VTA Pricing Engine.',
      '',
      'SANITIZED INQUIRY (remove names, phone numbers, emails, passport details and confidential net rates):',
      '[Paste inquiry here]'
    ].join('\n'),
    document: [
      'Act as the professional itinerary and proposal editor for Vietnam Travel Advisor (VTA).',
      'Rewrite the text below for a premium, client-ready Word/PDF tour proposal.',
      'Retain the source structure, tour duration, inclusions/exclusions and confirmed facts.',
      'Correct language, grammar and clarity; flag conflicting dates or missing details.',
      'Do not make up supplier prices, schedules, booking status or guarantees.',
      'Return only clean editable document text, with headings by day where appropriate.',
      '',
      'SANITIZED DOCUMENT TEXT (remove private guest data and confidential supplier rates):',
      '[Paste content here]'
    ].join('\n')
  });
  function buildPrompt(mode) {
    return prompts[Object.prototype.hasOwnProperty.call(prompts, mode) ? mode : 'itinerary'];
  }
  let currentDialog = null;
  function open({mode = 'itinerary', onApply = null} = {}) {
    if (!document.body) return false;
    if (currentDialog) currentDialog.remove();
    const previousFocus = document.activeElement;
    const overlay = document.createElement('div');
    overlay.className = 'vta-plus-overlay';
    overlay.setAttribute('data-vta-plus-dialog', '');
    overlay.innerHTML = '<section class="vta-plus-dialog" role="dialog" aria-modal="true" aria-labelledby="vta-plus-title">' +
      '<header><div><h2 id="vta-plus-title">ChatGPT Plus · VTA Assistant</h2><p>Không cần API Key · Sao chép thủ công · Không tự gửi dữ liệu</p></div>' +
      '<button type="button" class="vta-plus-close" data-plus-close aria-label="Đóng">×</button></header>' +
      '<p class="vta-plus-warning">Chỉ dán dữ liệu đã ẩn thông tin cá nhân. Không chia sẻ hộ chiếu, liên hệ khách, chi phí nhà cung cấp, mật khẩu hay API Key.</p>' +
      '<label for="vta-plus-prompt">Prompt dành cho ChatGPT (bạn có thể chỉnh sửa)</label>' +
      '<textarea id="vta-plus-prompt" rows="10" spellcheck="false"></textarea>' +
      '<div class="vta-plus-actions"><button type="button" data-plus-copy>Sao chép Prompt</button><button type="button" data-plus-open>Mở ChatGPT Plus ↗</button></div>' +
      (typeof onApply === 'function' ?
        '<label for="vta-plus-result">Dán kết quả từ ChatGPT để chèn vào tài liệu VTA</label>' +
        '<textarea id="vta-plus-result" rows="5" placeholder="Dán câu trả lời ChatGPT tại đây. Bạn phải kiểm tra lại trước khi chèn."></textarea>' +
        '<div class="vta-plus-actions"><button type="button" data-plus-apply>Chèn vào vị trí con trỏ</button></div>' : '') +
      '<p class="vta-plus-status" role="status" aria-live="polite"></p>' +
      '<p class="vta-plus-footnote">ChatGPT mở ở tab riêng. Không có đồng bộ đăng nhập Plus, API, giá supplier hoặc tự động cập nhật dữ liệu RC6.</p>' +
      '</section>';
    document.body.appendChild(overlay);
    currentDialog = overlay;
    const promptField = overlay.querySelector('#vta-plus-prompt');
    const status = overlay.querySelector('.vta-plus-status');
    promptField.value = buildPrompt(mode);
    function close() {
      if (currentDialog === overlay) currentDialog = null;
      overlay.remove();
      if (previousFocus && previousFocus.isConnected && typeof previousFocus.focus === 'function') previousFocus.focus();
    }
    overlay.querySelector('[data-plus-close]').addEventListener('click', close);
    overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
    overlay.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); close(); }
    });
    overlay.querySelector('[data-plus-copy]').addEventListener('click', async () => {
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(promptField.value);
        } else {
          promptField.focus(); promptField.select();
          if (!document.execCommand || !document.execCommand('copy')) throw Error('Clipboard unavailable');
        }
        status.textContent = 'Đã sao chép. Mở ChatGPT rồi dán prompt.';
      } catch (_error) {
        promptField.focus(); promptField.select();
        status.textContent = 'Trình duyệt không cho sao chép. Hãy chọn và sao chép nội dung thủ công.';
      }
    });
    overlay.querySelector('[data-plus-open]').addEventListener('click', () => {
      window.open(CHATGPT_URL, '_blank', 'noopener,noreferrer');
      status.textContent = 'Dán prompt vào ChatGPT trong tab mới. Không tự động truyền dữ liệu.';
    });
    const apply = overlay.querySelector('[data-plus-apply]');
    if (apply) apply.addEventListener('click', async () => {
      const answer = overlay.querySelector('#vta-plus-result').value.trim();
      if (!answer) { status.textContent = 'Dán kết quả ChatGPT trước khi chèn.'; return; }
      if (answer.length > 60000) { status.textContent = 'Nội dung quá dài (tối đa 60.000 ký tự).'; return; }
      if (!window.confirm('Đã rà soát nội dung AI và muốn chèn vào tài liệu tại vị trí con trỏ?')) return;
      apply.disabled = true;
      try { await onApply(answer); close(); }
      catch (error) { apply.disabled = false; status.textContent = error && error.message ? error.message : 'Không chèn được nội dung.'; }
    });
    promptField.focus();
    return true;
  }
  window.VTAPlusAssist = Object.freeze({buildPrompt, open});
})();
