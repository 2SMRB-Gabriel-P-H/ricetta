(() => {
  const token = new URLSearchParams(location.hash.slice(1)).get('token') || '';
  history.replaceState(null, '', location.pathname);
  if (/^[A-Za-z0-9_-]{20,100}$/.test(token)) {
    document.querySelector('#confirm-email-token').value = token;
    document.querySelector('#confirm-email-form').hidden = false;
  } else {
    document.querySelector('#confirm-email-message').textContent = 'El enlace no es válido o ha caducado. Solicita una nueva confirmación desde tu cuenta.';
  }
})();
