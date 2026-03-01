import React, { useState, useCallback, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { AgentUI } from '@automattic/agenttic-ui';
import '@automattic/agenttic-ui/index.css';
import './styles.css';

const config = window.ClawPressChat || {};

function generateMockResponses(name, context) {
  const site = context.site_title || 'your site';
  const tagline = context.site_tagline || '';

  return [
    {
      triggers: ['hello', 'hi', 'hey', 'start', ''],
      response: `Hey! I've been looking around **${site}** and I have some thoughts. Here's what I noticed:\n\n` +
        `1. 📝 **About page** — ${context.about_content ? 'You have one, but it could use some freshening up. Want me to suggest a rewrite?' : 'You don\'t seem to have one yet. Want me to draft one?'}\n` +
        `2. 🔍 **SEO** — Some of your pages are missing meta descriptions. That\'s low-hanging fruit.\n` +
        `3. 🎨 **Tagline** — ${tagline ? `"${tagline}" is fine, but we could make it punchier.` : 'You don\'t have a tagline set. That\'s a quick win.'}\n\n` +
        `What should we tackle first?`,
      action: 'Reviewed site and identified 3 improvement areas',
    },
    {
      triggers: ['about', 'about page'],
      response: `I took a look at your About page. Here's a draft update:\n\n` +
        `---\n\n` +
        `> **About ${site}**\n>\n` +
        `> ${tagline || 'We\'re glad you\'re here.'} ` +
        `This site is where ideas come to life. Whether you're here for the first time or the hundredth, there's always something new to discover.\n>\n` +
        `> Built with ❤️ and WordPress.\n\n` +
        `---\n\n` +
        `Want me to update the page with this? I can also tweak the tone if you'd prefer something different.`,
      action: 'Drafted About page update',
    },
    {
      triggers: ['seo', 'meta', 'description'],
      response: `Here's what I found on the SEO front:\n\n` +
        `| Page | Status |\n|------|--------|\n` +
        `| Home | ⚠️ No meta description |\n` +
        `| About | ⚠️ No meta description |\n` +
        `| Blog | ✅ Has one |\n\n` +
        `I can generate meta descriptions for the pages that are missing them. They'd be based on the page content — short, punchy, under 160 characters. Want me to go ahead?`,
      action: 'Audited meta descriptions across site pages',
    },
    {
      triggers: ['tagline', 'slogan'],
      response: `Here are a few tagline ideas for **${site}**:\n\n` +
        `1. "${site} — Where ideas take shape"\n` +
        `2. "${site} — Think different, build better"\n` +
        `3. "${site} — Your corner of the internet"\n\n` +
        `Pick one and I'll update it, or give me a direction and I'll riff on it.`,
      action: 'Generated tagline suggestions',
    },
    {
      triggers: ['update', 'go ahead', 'do it', 'yes', 'sure', 'yeah'],
      response: `Done! ✅ I've made the update and logged it. You can see my profile under **Users**.\n\nWhat's next?`,
      action: 'Applied suggested changes to the site',
    },
    {
      triggers: ['help', 'what can you do', 'capabilities'],
      response: `Here's what I can help with:\n\n` +
        `- 📝 **Content** — Draft, edit, or review pages and posts\n` +
        `- 🔍 **SEO** — Meta descriptions, titles, keyword suggestions\n` +
        `- 🎨 **Site identity** — Tagline, about page, branding\n` +
        `- 📊 **Review** — Look at your site structure and suggest improvements\n` +
        `- ✍️ **Writing** — Blog post ideas, outlines, drafts\n\n` +
        `Just tell me what you need!`,
      action: null,
    },
  ];
}

function findResponse(input, responses) {
  const lower = input.toLowerCase().trim();
  for (const r of responses) {
    for (const trigger of r.triggers) {
      if (trigger && lower.includes(trigger)) {
        return r;
      }
    }
  }
  return {
    response: `Got it! Let me think about that... I'd suggest we break this into smaller steps. What specifically about "${input}" would you like me to focus on?`,
    action: null,
  };
}

function ChatApp() {
  const name = config.name || 'Assistant';
  const context = config.context || {};
  const mockResponses = useRef(generateMockResponses(name, context));

  const [messages, setMessages] = useState([]);
  const [isProcessing, setIsProcessing] = useState(false);
  const idCounter = useRef(1);

  const hasGreeted = useRef(false);
  React.useEffect(() => {
    if (hasGreeted.current) return;
    hasGreeted.current = true;
    const greeting = findResponse('', mockResponses.current);
    const id = String(idCounter.current++);
    setTimeout(() => {
      setMessages([{
        id,
        role: 'agent',
        content: [{ type: 'text', text: greeting.response }],
        timestamp: Date.now(),
        archived: false,
        showIcon: true,
      }]);
      if (greeting.action) logAction(greeting.action);
    }, 500);
  }, []);

  const logAction = useCallback((action) => {
    if (!action) return;
    const formData = new FormData();
    formData.append('action', 'clawpress_assistant_mock_action');
    formData.append('_nonce', config.nonce);
    formData.append('assistant_id', config.userId);
    formData.append('mock_action', action);
    fetch(config.ajaxUrl, { method: 'POST', body: formData });
  }, []);

  const onSubmit = useCallback((text) => {
    const userMsg = {
      id: String(idCounter.current++),
      role: 'user',
      content: [{ type: 'text', text }],
      timestamp: Date.now(),
      archived: false,
      showIcon: true,
    };

    setMessages(prev => [...prev, userMsg]);
    setIsProcessing(true);

    setTimeout(() => {
      const match = findResponse(text, mockResponses.current);
      const agentMsg = {
        id: String(idCounter.current++),
        role: 'agent',
        content: [{ type: 'text', text: match.response }],
        timestamp: Date.now(),
        archived: false,
        showIcon: true,
      };
      setMessages(prev => [...prev, agentMsg]);
      setIsProcessing(false);
      if (match.action) logAction(match.action);
    }, 800 + Math.random() * 1200);
  }, [logAction]);

  const suggestions = messages.length <= 1 ? [
    { id: '1', label: '📝 About page', prompt: 'Let\'s work on the about page' },
    { id: '2', label: '🔍 SEO check', prompt: 'Check my SEO' },
    { id: '3', label: '💬 What can you do?', prompt: 'What can you do?' },
  ] : [];

  return (
    <AgentUI
      messages={messages}
      isProcessing={isProcessing}
      onSubmit={onSubmit}
      suggestions={suggestions}
      clearSuggestions={() => {}}
      variant="embedded"
      placeholder={`Ask ${name} anything...`}
    />
  );
}

const root = document.getElementById('clawpress-assistant-chat-root');
if (root) {
  createRoot(root).render(<ChatApp />);
}
