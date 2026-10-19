// Platform page: store chips in each store's colour, and the sign-up form.

for (const chip of document.querySelectorAll(".store-chip")) {
  chip.style.setProperty("--accent", chip.dataset.color);
}

const form = document.getElementById("signup");
const result = document.getElementById("signup-result");

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const button = form.querySelector("button");
  button.disabled = true;
  result.className = "";
  result.textContent = "";

  try {
    const response = await fetch("/api/v1/stores", {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(Object.fromEntries(new FormData(form).entries())),
    });
    const data = await response.json();
    console.info(`POST /api/v1/stores → ${response.status}`, data);

    if (!response.ok) {
      const fields = (data.error?.details ?? []).map((d) => d.message).join(" ");
      result.className = "notice error";
      result.textContent = `Não foi possível criar a loja. ${fields}`;
      return;
    }

    result.className = "notice ok";
    result.textContent = "Loja criada! Abrindo…";
    // The new store is another origin: hand it the owner's token in the
    // URL fragment, which is never sent to any server.
    location.href = `${data.data.store.url}#token=${encodeURIComponent(data.data.token)}`;
  } catch {
    result.className = "notice error";
    result.textContent = "Falha de conexão. Tente de novo.";
  } finally {
    button.disabled = false;
  }
});
