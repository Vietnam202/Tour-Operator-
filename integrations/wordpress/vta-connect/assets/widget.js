/* VTA Connect 0.1: no third-party JS, no provider tokens in browser. */
(() => {
  'use strict';
  const randomId = () => {
    const bytes = new Uint8Array(20);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  };
  const faq = (text) => {
    const q = text.toLowerCase();
    if (/price|cost|quote|báo giá|giá tour/.test(q)) {
      return 'For an accurate quote, please send your travel dates, destinations and number of travelers. VTA staff will check the itinerary and approved pricing.';
    }
    if (/itinerary|program|chương trình|lịch trình/.test(q)) {
      return 'We can share a reviewed tour outline. Please tell us your preferred destination and trip length. Our team will send an approved itinerary link.';
    }
    if (/private|riêng|family/.test(q)) {
      return 'Private itineraries can be tailored to your group. Please share your preferred route, travel dates and group size.';
    }
    return '';
  };
  const attribution = () => {
    const query = new URLSearchParams(location.search);
    const obj = {};
    ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id'].forEach(k => {
      obj[k] = (query.get(k) || '').slice(0, 500);
    });
    obj.landing_url = (location.origin + location.pathname).slice(0, 500);
    obj.referrer = document.referrer.slice(0, 500);
    return obj;
  };
  const call = async (root, route, data) => {
    const response = await fetch(root.dataset.api + route, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.ok) throw new Error(result.message || 'The connection is unavailable.');
    return result;
  };
  const bubble = (root, text, role) => {
    const box = root.querySelector('[data-messages]');
    const p = document.createElement('p');
    p.className = 'vta-connect__bubble vta-connect__bubble--' + role;
    // Deliberately textContent: never insert customer/chat content as HTML.
    if (role === 'staff') {
      // Approved RC6 share URLs become clickable without interpreting any HTML.
      for (const segment of text.split(/(https:\/\/[^\s<>]+)/g)) {
        if (!segment.startsWith('https://')) { p.appendChild(document.createTextNode(segment)); continue; }
        try {
          const url = new URL(segment);
          if (url.protocol !== 'https:') throw new Error('Unsupported URL');
          const a = document.createElement('a');
          a.href = url.href;
          a.textContent = segment;
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          a.referrerPolicy = 'no-referrer';
          p.appendChild(a);
        } catch (_) { p.appendChild(document.createTextNode(segment)); }
      }
    } else p.textContent = text;
    box.appendChild(p);
    box.scrollTop = box.scrollHeight;
  };
  const say = (root, text, error) => {
    const node = root.querySelector('[data-notice]');
    node.textContent = text;
    node.classList.toggle('vta-connect__notice--error', !!error);
  };
  document.querySelectorAll('[data-vta-chat]').forEach(root => {
    const chatForm = root.querySelector('[data-chat-form]');
    const leadForm = root.querySelector('[data-lead-form]');
    let busy = false;
    let pendingChat = null;
    let pendingLead = null;
    let cursor = 0;
    let live = true;
    const seen = new Set();
    try { cursor = parseInt(sessionStorage.getItem('vta-connect-cursor') || '0', 10) || 0; } catch (_) {}

    async function sendChat(text) {
      if (busy || !text.trim()) return;
      busy = true;
      chatForm.querySelector('button[type="submit"]').disabled = true;
      const message = text.trim();
      const requestId = pendingChat && pendingChat.text === message ? pendingChat.id : randomId();
      pendingChat = {text: message, id: requestId};
      try {
        await call(root, 'chat', {
          request_id: requestId, text: message, website: chatForm.elements.website.value,
          attribution: attribution()
        });
        pendingChat = null;
        bubble(root, text, 'visitor');
        chatForm.elements.text.value = '';
        const canned = faq(text);
        if (canned) bubble(root, canned, 'faq');
        say(root, 'Message received. Staff replies will appear here.', false);
      } catch (error) {
        say(root, error.message + ' Your message was not confirmed as sent.', true);
      } finally {
        busy = false;
        chatForm.querySelector('button[type="submit"]').disabled = false;
      }
    }
    chatForm.addEventListener('submit', event => {
      event.preventDefault();
      sendChat(chatForm.elements.text.value);
    });
    root.querySelectorAll('[data-question]').forEach(button => {
      button.addEventListener('click', () => sendChat(button.dataset.question));
    });
    leadForm.addEventListener('submit', async event => {
      event.preventDefault();
      const button = leadForm.querySelector('button[type="submit"]');
      button.disabled = true;
      const data = Object.fromEntries(new FormData(leadForm));
      const digest = JSON.stringify(data);
      const leadId = pendingLead && pendingLead.digest === digest ? pendingLead.id : randomId();
      pendingLead = {digest: digest, id: leadId};
      try {
        await call(root, 'lead', {
          request_id: leadId,
          contact_name: data.contact_name, email: data.email,
          destination: data.destination, total_guests: Number(data.total_guests),
          message: data.message, website: data.website, attribution: attribution()
        });
        pendingLead = null;
        bubble(root, 'Quotation request received. VTA Sales will review your details.', 'faq');
        say(root, 'Your quotation request was received by VTA.', false);
        leadForm.reset();
      } catch (error) {
        say(root, error.message + ' Your request was not confirmed as received.', true);
      } finally { button.disabled = false; }
    });

    async function poll() {
      if (!live || document.visibilityState !== 'visible') return;
      try {
        const result = await call(root, 'poll', { after_id: cursor });
        const delivered = [];
        for (const msg of (result.messages || [])) {
          if (!Number.isSafeInteger(msg.id) || msg.id < 1) continue;
          delivered.push(msg.id);
          if (!seen.has(msg.id)) {
            bubble(root, String(msg.text), 'staff');
            seen.add(msg.id);
          }
        }
        if (delivered.length) {
          // Retry ACK even for messages already displayed if the prior ACK failed.
          // This confirms relay to the widget, NOT that the customer has read it.
          await call(root, 'ack', { message_ids: delivered });
          cursor = Math.max(cursor, ...delivered);
          try { sessionStorage.setItem('vta-connect-cursor', String(cursor)); } catch (_) {}
        }
      } catch (_) {
        // Chat may be offline or not yet configured. Never claim delivery.
      }
    }
    const timer = setInterval(poll, 12000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
    window.addEventListener('pagehide', () => { live = false; clearInterval(timer); });
    poll();
  });
})();
