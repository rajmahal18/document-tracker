function openAccessModal(){
  const el = document.getElementById("accessModal");
  if (!el) return;

  el.classList.add("open");
  document.body.classList.add("modalOpen");   // ⭐ IMPORTANT
}

function closeAccessModal(){
  const el = document.getElementById("accessModal");
  if (!el) return;

  el.classList.remove("open");
  document.body.classList.remove("modalOpen"); // ⭐ IMPORTANT
}

async function submitAccessRequest(e){
  e.preventDefault();

  const form = document.getElementById("accessForm");
  const msg  = document.getElementById("accessMsg");
  if (!form || !msg) return;

  msg.style.display = "none";
  msg.textContent = "";

  const APP = window.__APP__ || {};
  const API = APP.api || "/document-tracker/api";

  try{
    const res = await fetch(`${API}/request_access.php`, {
      method: "POST",
      body: new FormData(form),
      credentials: "same-origin",
      headers: { "Accept": "application/json" }
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok || !data.ok){
      msg.style.display = "block";
      msg.className = "modalMsg error";
      msg.textContent = data.error || "Failed to submit request.";
      return;
    }

    msg.style.display = "block";
    msg.className = "modalMsg success";
    msg.textContent = data.message || "Request submitted.";

    form.reset();
    setTimeout(() => closeAccessModal(), 900);
  } catch(err){
    msg.style.display = "block";
    msg.className = "modalMsg error";
    msg.textContent = "Network error. Please try again.";
  }
}