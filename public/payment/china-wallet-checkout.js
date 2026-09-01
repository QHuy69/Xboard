(() => {
  "use strict";

  const root = document.querySelector("[data-china-wallet-checkout]");
  if (!root) return;

  const allowedWallets = new Set(["wechatpay", "alipay"]);
  const walletButtons = Array.from(root.querySelectorAll("[data-wallet-option]"));
  const walletName = root.querySelector("[data-active-wallet-name]");
  const walletMark = root.querySelector("[data-active-wallet-mark]");
  const instruction = root.querySelector("[data-wallet-instruction]");
  const qrImage = root.querySelector("[data-payment-qr-image]");
  const qrDemo = root.querySelector("[data-payment-qr-demo]");
  const demoBadge = root.querySelector("[data-demo-badge]");
  const state = root.querySelector("[data-payment-state]");
  const countdown = root.querySelector("[data-payment-countdown]");
  const createButton = root.querySelector("[data-create-payment]");
  const previewMode = root.dataset.preview === "1";
  const createEndpoint = String(root.dataset.createEndpoint || "").trim();
  const defaultReturnUrl = String(root.dataset.returnUrl || "/orders");
  const initialWallet = allowedWallets.has(root.dataset.wallet) ? root.dataset.wallet : "wechatpay";
  let selectedWallet = initialWallet;
  let statusEndpoint = "";
  let expiresAt = Number(root.dataset.expiresAt || 0) * 1000;
  let pollTimer = null;
  let clockTimer = null;
  let creating = false;

  const labels = {
    preview: String(root.dataset.previewMessage || "Payment provider is not connected yet."),
    preparing: String(root.dataset.preparingMessage || "Preparing payment QR code..."),
    waiting: String(root.dataset.waitingMessage || "Waiting for payment confirmation..."),
    checking: String(root.dataset.checkingMessage || "Checking payment status..."),
    paid: String(root.dataset.paidMessage || "Payment successful."),
    cancelled: String(root.dataset.cancelledMessage || "Payment was cancelled."),
    expired: String(root.dataset.expiredMessage || "This QR code has expired."),
    failed: String(root.dataset.failedMessage || "Unable to prepare payment. Please try again."),
    remaining: String(root.dataset.remainingLabel || "Time remaining")
  };

  const walletCopy = {
    wechatpay: {
      name: String(root.dataset.wechatName || "WeChat Pay"),
      mark: "微",
      instruction: String(root.dataset.wechatInstruction || "Open WeChat and scan this code with Scan.")
    },
    alipay: {
      name: String(root.dataset.alipayName || "Alipay"),
      mark: "支",
      instruction: String(root.dataset.alipayInstruction || "Open Alipay and scan this code with Scan.")
    }
  };

  const sameOriginUrl = (value) => {
    if (!value) return null;
    try {
      const candidate = new URL(String(value), window.location.origin);
      if (candidate.origin !== window.location.origin) return null;
      if (!/^https?:$/.test(candidate.protocol)) return null;
      if (candidate.username || candidate.password) return null;
      return candidate;
    } catch (_error) {
      return null;
    }
  };

  const setState = (kind, message) => {
    root.dataset.state = kind;
    if (state) state.textContent = message;
  };

  const selectWallet = (wallet) => {
    if (!allowedWallets.has(wallet) || creating) return;
    selectedWallet = wallet;
    root.dataset.wallet = wallet;
    walletButtons.forEach((button) => {
      button.setAttribute("aria-pressed", button.dataset.walletOption === wallet ? "true" : "false");
    });
    if (walletName) walletName.textContent = walletCopy[wallet].name;
    if (walletMark) walletMark.textContent = walletCopy[wallet].mark;
    if (instruction) instruction.textContent = walletCopy[wallet].instruction;
    if (previewMode) setState("preview", labels.preview);
  };

  const stopTimers = () => {
    if (pollTimer) window.clearInterval(pollTimer);
    if (clockTimer) window.clearInterval(clockTimer);
    pollTimer = null;
    clockTimer = null;
  };

  const formatRemaining = () => {
    if (!countdown) return true;
    if (!Number.isFinite(expiresAt) || expiresAt <= 0) {
      countdown.hidden = true;
      return true;
    }
    countdown.hidden = false;
    const seconds = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
    const minutes = String(Math.floor(seconds / 60)).padStart(2, "0");
    const remainder = String(seconds % 60).padStart(2, "0");
    countdown.textContent = `${labels.remaining}: ${minutes}:${remainder}`;
    if (seconds === 0) {
      setState("expired", labels.expired);
      stopTimers();
      if (createButton) createButton.disabled = false;
      return false;
    }
    return true;
  };

  const redirectAfterPaid = (returnUrl) => {
    const safeReturnUrl = sameOriginUrl(returnUrl) || sameOriginUrl(defaultReturnUrl);
    if (safeReturnUrl) window.setTimeout(() => window.location.assign(safeReturnUrl.href), 900);
  };

  const pollStatus = async () => {
    const endpoint = sameOriginUrl(statusEndpoint);
    if (!endpoint || !formatRemaining()) return;
    setState("checking", labels.checking);
    try {
      const response = await fetch(endpoint.href, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
      if (!response.ok) throw new Error("payment status unavailable");
      const payload = await response.json();
      const paymentStatus = String(payload && payload.status || "pending").toLowerCase();
      if (paymentStatus === "paid" || paymentStatus === "success") {
        setState("paid", labels.paid);
        stopTimers();
        redirectAfterPaid(payload.return_url);
      } else if (paymentStatus === "cancelled" || paymentStatus === "canceled") {
        setState("cancelled", labels.cancelled);
        stopTimers();
        if (createButton) createButton.disabled = false;
      } else if (paymentStatus === "expired") {
        setState("expired", labels.expired);
        stopTimers();
        if (createButton) createButton.disabled = false;
      } else {
        setState("waiting", labels.waiting);
      }
    } catch (_error) {
      setState("waiting", labels.waiting);
    }
  };

  const renderQr = (payload) => {
    const imageUrl = sameOriginUrl(payload && payload.qr_image_url);
    const nextStatusEndpoint = sameOriginUrl(payload && payload.status_url);
    const nextExpiresAt = Number(payload && payload.expires_at || 0) * 1000;
    if (!imageUrl || !nextStatusEndpoint || !Number.isFinite(nextExpiresAt) || nextExpiresAt <= Date.now()) {
      throw new Error("invalid payment response");
    }
    statusEndpoint = nextStatusEndpoint.href;
    expiresAt = nextExpiresAt;
    if (qrImage) {
      qrImage.src = imageUrl.href;
      qrImage.hidden = false;
    }
    if (qrDemo) qrDemo.hidden = true;
    if (demoBadge) demoBadge.hidden = true;
    setState("waiting", labels.waiting);
    formatRemaining();
    stopTimers();
    clockTimer = window.setInterval(formatRemaining, 1000);
    pollTimer = window.setInterval(pollStatus, 5000);
    pollStatus();
  };

  const createPayment = async () => {
    if (creating) return;
    if (previewMode || !createEndpoint) {
      setState("preview", labels.preview);
      return;
    }
    const endpoint = sameOriginUrl(createEndpoint);
    if (!endpoint) {
      setState("failed", labels.failed);
      return;
    }
    creating = true;
    walletButtons.forEach((button) => { button.disabled = true; });
    if (createButton) createButton.disabled = true;
    setState("preparing", labels.preparing);
    try {
      const response = await fetch(endpoint.href, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": String(root.dataset.csrfToken || "")
        },
        body: JSON.stringify({
          trade_no: String(root.dataset.tradeNo || ""),
          wallet: selectedWallet
        })
      });
      if (!response.ok) throw new Error("payment create failed");
      renderQr(await response.json());
    } catch (_error) {
      setState("failed", labels.failed);
      walletButtons.forEach((button) => { button.disabled = false; });
      if (createButton) createButton.disabled = false;
    } finally {
      creating = false;
    }
  };

  walletButtons.forEach((button) => {
    button.addEventListener("click", () => selectWallet(String(button.dataset.walletOption || "")));
  });
  if (createButton) createButton.addEventListener("click", createPayment);
  window.addEventListener("pagehide", stopTimers);
  selectWallet(initialWallet);
  formatRemaining();
})();
