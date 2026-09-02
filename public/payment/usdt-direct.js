(() => {
  "use strict";

  const root = document.querySelector("[data-usdt-checkout]");
  if (!root) return;

  const statusBox = root.querySelector("[data-payment-status]");
  const statusText = root.querySelector("[data-status-text]");
  const countdown = root.querySelector("[data-countdown]");
  const refreshButton = root.querySelector("[data-refresh-status]");
  const toast = root.querySelector("[data-copy-toast]");
  const copyButtons = Array.from(root.querySelectorAll("[data-copy-value]"));
  const expiresAt = Number(root.dataset.expiresAt || 0) * 1000;
  const defaultReturnUrl = String(root.dataset.returnUrl || "/orders");
  const initialStatus = String(root.dataset.initialStatus || "pending").toLowerCase();
  const labels = {
    copied: String(root.dataset.labelCopied || "Copied"),
    copyFailed: String(root.dataset.labelCopyFailed || "Could not copy."),
    pending: String(root.dataset.labelPending || "Waiting for payment."),
    checking: String(root.dataset.labelChecking || "Checking payment status..."),
    confirming: String(root.dataset.labelConfirming || "Waiting for confirmation..."),
    paid: String(root.dataset.labelPaid || "Payment successful."),
    expired: String(root.dataset.labelExpired || "This invoice has expired."),
    manualReview: String(root.dataset.labelReview || "Manual review is required."),
    cancelled: String(root.dataset.labelCancelled || "This order was cancelled."),
    networkError: String(root.dataset.labelNetworkError || "Unable to refresh. Retrying..."),
    remaining: String(root.dataset.labelRemaining || "Time remaining")
  };
  let disposed = false;
  let polling = false;
  let pollTimer = null;
  let countdownTimer = null;
  let redirectTimer = null;
  let toastTimer = null;
  let activeRequest = null;
  let lastKnownStatus = initialStatus;

  const sameOriginUrl = (value) => {
    try {
      const candidate = new URL(String(value || ""), window.location.origin);
      if (candidate.origin !== window.location.origin) return null;
      if (!/^https?:$/.test(candidate.protocol)) return null;
      if (candidate.username || candidate.password) return null;
      return candidate;
    } catch (_error) {
      return null;
    }
  };

  const statusEndpoint = sameOriginUrl(root.dataset.statusUrl);

  const setStatus = (state, message) => {
    if (disposed) return;
    if (statusBox) statusBox.dataset.state = state;
    if (statusText) statusText.textContent = message;
  };

  const showToast = (message) => {
    if (!toast || disposed) return;
    if (toastTimer) window.clearTimeout(toastTimer);
    toast.textContent = message;
    toast.hidden = false;
    toastTimer = window.setTimeout(() => {
      toast.hidden = true;
      toast.textContent = "";
    }, 2200);
  };

  const copyFallback = (value) => {
    const helper = document.createElement("textarea");
    helper.className = "usdt-copy-helper";
    helper.value = value;
    helper.setAttribute("readonly", "");
    document.body.appendChild(helper);
    helper.select();
    helper.setSelectionRange(0, helper.value.length);
    let copied = false;
    try {
      copied = document.execCommand("copy");
    } catch (_error) {
      copied = false;
    }
    helper.remove();
    return copied;
  };

  const copyValue = async (value) => {
    let copied = false;
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(value);
        copied = true;
      } catch (_error) {
        copied = false;
      }
    }
    if (!copied) copied = copyFallback(value);
    showToast(copied ? labels.copied : labels.copyFailed);
  };

  const formatCountdown = () => {
    if (!countdown || disposed) return;
    if (!Number.isFinite(expiresAt) || expiresAt <= 0) {
      countdown.textContent = "";
      return;
    }
    const seconds = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
    if (seconds === 0) {
      countdown.textContent = labels.expired;
      return;
    }
    const hours = String(Math.floor(seconds / 3600)).padStart(2, "0");
    const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
    const remainder = String(seconds % 60).padStart(2, "0");
    countdown.textContent = `${labels.remaining}: ${hours}:${minutes}:${remainder}`;
  };

  const schedulePaidRedirect = (returnUrl) => {
    const target = sameOriginUrl(returnUrl) || sameOriginUrl(defaultReturnUrl);
    if (!target || redirectTimer || disposed) return;
    redirectTimer = window.setTimeout(() => {
      if (!disposed) window.location.assign(target.href);
    }, 1200);
  };

  const applyPaymentStatus = (paymentStatus, returnUrl) => {
    if (paymentStatus === "paid" || paymentStatus === "success") {
      lastKnownStatus = "paid";
      setStatus("paid", labels.paid);
      schedulePaidRedirect(returnUrl);
    } else if (paymentStatus === "confirming" || paymentStatus === "seen" || paymentStatus === "confirmed") {
      lastKnownStatus = "confirming";
      setStatus("confirming", labels.confirming);
    } else if (paymentStatus === "manual_review") {
      lastKnownStatus = "manual_review";
      setStatus("manual_review", labels.manualReview);
    } else if (paymentStatus === "expired") {
      lastKnownStatus = "expired";
      setStatus("expired", labels.expired);
    } else if (paymentStatus === "cancelled" || paymentStatus === "canceled") {
      lastKnownStatus = "cancelled";
      setStatus("cancelled", labels.cancelled);
    } else {
      lastKnownStatus = "pending";
      setStatus("pending", labels.pending);
    }
  };

  const pollStatus = async (manual = false) => {
    if (disposed || polling || !statusEndpoint) return;
    if (!manual && document.visibilityState === "hidden") return;
    polling = true;
    if (refreshButton) refreshButton.disabled = true;
    if (manual) setStatus("checking", labels.checking);
    activeRequest = new AbortController();
    try {
      const response = await fetch(statusEndpoint.href, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        signal: activeRequest.signal,
        headers: { Accept: "application/json" }
      });
      if (!response.ok) throw new Error("payment status unavailable");
      const payload = await response.json();
      if (disposed) return;
      const paymentStatus = String(payload && payload.status || "pending").toLowerCase();
      applyPaymentStatus(paymentStatus, payload && payload.return_url);
    } catch (error) {
      if (!disposed && error && error.name !== "AbortError") {
        if (lastKnownStatus === "pending") {
          setStatus("network_error", labels.networkError);
        } else {
          applyPaymentStatus(lastKnownStatus, defaultReturnUrl);
        }
      }
    } finally {
      polling = false;
      activeRequest = null;
      if (refreshButton && !disposed) refreshButton.disabled = false;
    }
  };

  const dispose = () => {
    disposed = true;
    if (pollTimer) window.clearInterval(pollTimer);
    if (countdownTimer) window.clearInterval(countdownTimer);
    if (redirectTimer) window.clearTimeout(redirectTimer);
    if (toastTimer) window.clearTimeout(toastTimer);
    if (activeRequest) activeRequest.abort();
  };

  copyButtons.forEach((button) => {
    button.addEventListener("click", () => copyValue(String(button.dataset.copyValue || "")));
  });
  if (refreshButton) refreshButton.addEventListener("click", () => pollStatus(true));
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") pollStatus(false);
  });
  window.addEventListener("pagehide", dispose);

  applyPaymentStatus(initialStatus, defaultReturnUrl);
  formatCountdown();
  countdownTimer = window.setInterval(formatCountdown, 1000);
  pollTimer = window.setInterval(() => pollStatus(false), 5000);
  pollStatus(false);
})();
