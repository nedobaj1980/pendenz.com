// assets/js/chat_boot.js
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('chat-root');
  if (el && window.TeamChat) TeamChat.mountChat(el, {roomId: Number(el.dataset.roomId || 1)});
});
