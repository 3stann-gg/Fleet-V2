/* ==========================================
   Route templates (localStorage)
========================================== */

function renderRouteTemplateSelect() {
  const select = document.getElementById("routeTemplateSelect");
  if (!select) return;
  const current = select.value;
  const templates =
    typeof readRouteTemplates === "function" ? readRouteTemplates() : [];

  select.innerHTML =
    '<option value="">Route templates…</option>' +
    templates
      .map((t) => {
        const name = String(t.name)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/"/g, "&quot;");
        return `<option value="${t.id}">${name}</option>`;
      })
      .join("");

  if (current && [...select.options].some((o) => o.value === current)) {
    select.value = current;
  }

  const del = document.getElementById("deleteRouteTemplate");
  const rename = document.getElementById("renameRouteTemplate");
  const apply = document.getElementById("applyRouteTemplate");
  const has = Boolean(select.value);
  if (del) del.disabled = !has;
  if (rename) rename.disabled = !has;
  if (apply) apply.disabled = !has;
}

function saveRouteAsTemplateFromForm() {
  const name = window.prompt("Template name");
  if (name == null) return;
  const trimmed = name.trim();
  if (!trimmed) {
    if (typeof showToast === "function") {
      showToast("Template name is required.", "warning");
    }
    return;
  }

  const data =
    typeof collectRouteFormData === "function" ? collectRouteFormData() : null;
  if (!data || !data.origin || !data.destination) {
    if (typeof showToast === "function") {
      showToast("Origin and destination are required to save a template.", "warning");
    }
    return;
  }

  const list = readRouteTemplates();
  const existing = list.find(
    (t) => t.name.toLowerCase() === trimmed.toLowerCase(),
  );
  if (existing) {
    const ok = window.confirm(
      'A template named "' +
        trimmed +
        '" already exists. Replace it?',
    );
    if (!ok) return;
  }

  const now = new Date().toISOString();
  const template = {
      id: existing?.id || "tpl-" + Date.now().toString(36),
      name: trimmed,
      origin: data.origin,
      destination: data.destination,
      stops: data.stops || [],
      priority: data.priority || "Normal",
      department: data.department || "",
      purpose: data.purpose || "",
      recommendedVehicle: data.vehicle || "",
      optimizationStrategy: data.optimizationStrategy || "",
      createdAt: existing?.createdAt || now,
      updatedAt: now,
  };

  const next = existing
    ? list.map((t) => (t.id === existing.id ? template : t))
    : [template, ...list];

  if (!writeRouteTemplates(next)) {
    if (typeof showToast === "function") {
      showToast("Unable to save template.", "error");
    }
    return;
  }

  renderRouteTemplateSelect();
  const select = document.getElementById("routeTemplateSelect");
  if (select) select.value = template.id;

  if (typeof showToast === "function") {
    showToast(existing ? "Template updated." : "Template saved.", "success");
  }
}

function applySelectedRouteTemplate() {
    const select = document.getElementById("routeTemplateSelect");
    const id = select?.value;
    if (!id) return;

    const template = readRouteTemplates().find((t) => t.id === id);
    if (!template) {
        renderRouteTemplateSelect();
        return;
    }

    /* Open new route form if closed, then apply */
    const modal = document.getElementById("routeFormModal");
    if (!modal?.classList.contains("show")) {
        if (typeof openRouteFormModal === "function") {
            openRouteFormModal("add");
        }
    }

    document.getElementById("routeOrigin").value = template.origin || "";
    document.getElementById("routeDestination").value =
        template.destination || "";
    if (typeof renderRouteStops === "function") {
        renderRouteStops(
            template.stops && template.stops.length ? template.stops : [""],
        );
    }
    if (template.priority) {
        document.getElementById("routePriority").value = template.priority;
    }
    if (template.department) {
        document.getElementById("routeDepartment").value = template.department;
    }
    if (template.purpose) {
        document.getElementById("routePurpose").value = template.purpose;
    }
    /*
  if (template.recommendedVehicle) {
    document.getElementById("routeVehicle").value = template.recommendedVehicle;
  }
  if (template.optimizationStrategy) {
    document.getElementById("routeOptStrategy").value =
      template.optimizationStrategy;
  }
  */
    if (typeof showToast === "function") {
        showToast("Template applied: " + template.name, "success");
    }
}

function renameSelectedRouteTemplate() {
  const select = document.getElementById("routeTemplateSelect");
  const id = select?.value;
  if (!id) return;
  const list = readRouteTemplates();
  const template = list.find((t) => t.id === id);
  if (!template) return;

  const next = window.prompt("Rename template", template.name);
  if (next == null) return;
  const name = next.trim();
  if (!name) {
    if (typeof showToast === "function") {
      showToast("Template name cannot be empty.", "warning");
    }
    return;
  }

  const clash = list.find(
    (t) => t.id !== id && t.name.toLowerCase() === name.toLowerCase(),
  );
  if (clash) {
    if (typeof showToast === "function") {
      showToast("A template with that name already exists.", "warning");
    }
    return;
  }

  template.name = name;
  template.updatedAt = new Date().toISOString();
  if (!writeRouteTemplates(list)) return;
  renderRouteTemplateSelect();
  if (select) select.value = id;
  if (typeof showToast === "function") {
    showToast("Template renamed.", "success");
  }
}

function deleteSelectedRouteTemplate() {
  const select = document.getElementById("routeTemplateSelect");
  const id = select?.value;
  if (!id) return;
  const list = readRouteTemplates();
  const template = list.find((t) => t.id === id);
  if (!template) return;

  const ok = window.confirm(
    'Delete template "' + template.name + '"?\n\nThis cannot be undone.',
  );
  if (!ok) return;

  if (!writeRouteTemplates(list.filter((t) => t.id !== id))) return;
  renderRouteTemplateSelect();
  if (typeof showToast === "function") {
    showToast("Template deleted.", "success");
  }
}

function initRouteTemplates() {
  const select = document.getElementById("routeTemplateSelect");
  if (!select || select.dataset.templatesInit === "true") {
    renderRouteTemplateSelect();
    return;
  }
  select.dataset.templatesInit = "true";
  renderRouteTemplateSelect();

  select.addEventListener("change", () => {
    const has = Boolean(select.value);
    ["deleteRouteTemplate", "renameRouteTemplate", "applyRouteTemplate"].forEach(
      (id) => {
        const el = document.getElementById(id);
        if (el) el.disabled = !has;
      },
    );
  });

  document
    .getElementById("saveRouteTemplate")
    ?.addEventListener("click", (e) => {
      e.preventDefault();
      /* Prefer form data; if modal closed, still allow if form has values */
      const modal = document.getElementById("routeFormModal");
      if (!modal?.classList.contains("show")) {
        if (typeof openRouteFormModal === "function") {
          openRouteFormModal("add");
        }
        if (typeof showToast === "function") {
          showToast("Fill the route form, then save as template.", "info");
        }
        return;
      }
      saveRouteAsTemplateFromForm();
    });

  document
    .getElementById("saveRouteTemplateFromForm")
    ?.addEventListener("click", (e) => {
      e.preventDefault();
      saveRouteAsTemplateFromForm();
    });

  document
    .getElementById("applyRouteTemplate")
    ?.addEventListener("click", (e) => {
      e.preventDefault();
      applySelectedRouteTemplate();
    });

  document
    .getElementById("renameRouteTemplate")
    ?.addEventListener("click", (e) => {
      e.preventDefault();
      renameSelectedRouteTemplate();
    });

  document
    .getElementById("deleteRouteTemplate")
    ?.addEventListener("click", (e) => {
      e.preventDefault();
      deleteSelectedRouteTemplate();
    });
}
