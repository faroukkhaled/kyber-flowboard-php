"use strict";
let config,
  records = [],
  editing = null;
const $ = (id) => document.getElementById(id);
const element = (tag, text, cls) => {
  const e = document.createElement(tag);
  if (text !== undefined) e.textContent = String(text);
  if (cls) e.className = cls;
  return e;
};
async function api(path, options = {}) {
  const response = await fetch("/api/" + path, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      "X-Kyber-Client": "workspace",
      ...options.headers,
    },
  });
  const body = await response.json();
  if (!response.ok) throw Error(body.error || "Request failed");
  return body;
}
function fieldControl(field) {
  const label = element("label", field.label);
  label.htmlFor = "field-" + field.key;
  let input;
  if (field.options) {
    input = element("select");
    for (const option of field.options) {
      const o = element("option", option);
      o.value = option;
      input.append(o);
    }
  } else {
    input = element(field.type === "textarea" ? "textarea" : "input");
    if (input.tagName === "INPUT") input.type = field.type || "text";
  }
  input.id = label.htmlFor;
  input.name = field.key;
  input.required = !!field.required;
  input.maxLength = 2000;
  if (field.min !== undefined) input.min = field.min;
  if (field.max !== undefined) input.max = field.max;
  if (field.step) input.step = field.step;
  return [label, input];
}
function buildForm() {
  const form = $("record-form");
  form.replaceChildren();
  for (const f of config.fields) form.append(...fieldControl(f));
  const save = element("button", "Save record");
  save.type = "submit";
  form.append(save);
  const cancel = element("button", "Cancel editing", "secondary");
  cancel.type = "button";
  cancel.onclick = reset;
  form.append(cancel);
}
function reset() {
  editing = null;
  $("record-form").reset();
  $("form-title").textContent = "New record";
}
function render() {
  const q = $("search").value.toLowerCase();
  const shown = records.filter((r) =>
    JSON.stringify(r).toLowerCase().includes(q),
  );
  $("columns").replaceChildren();
  const tr = element("tr");
  tr.append(element("th", "ID"));
  for (const f of config.fields) tr.append(element("th", f.label));
  tr.append(element("th", "Actions"));
  $("columns").append(tr);
  $("rows").replaceChildren();
  for (const row of shown) {
    const tr = element("tr");
    tr.append(element("td",row.id));
    for (const f of config.fields) tr.append(element("td", row[f.key] ?? ""));
    const actions = element("td");
    const edit = element("button", "Edit", "secondary");
    edit.onclick = () => {
      editing = row.id;
      $("form-title").textContent = "Edit record";
      for (const f of config.fields)
        $("field-" + f.key).value = row[f.key] ?? "";
      $("record-form").scrollIntoView({ behavior: "smooth", block: "center" });
    };
    actions.append(edit);
    for (const action of config.actions || []) {
      const button = element("button", action.label, "secondary");
      button.onclick = async () => {
        let input = {};
        if (action.prompt) {
          const value = prompt(action.prompt);
          if (value === null) return;
          input.value = value;
        }
        await run(() =>
          api("records/" + row.id + "/actions/" + action.key, {
            method: "POST",
            body: JSON.stringify(input),
          }),
        );
      };
      actions.append(button);
    }
    tr.append(actions);
    $("rows").append(tr);
  }
  $("empty").textContent = shown.length
    ? ""
    : records.length
      ? "No matching records."
      : "No records yet. Add your first record using the form.";
}
async function refresh() {
  const data = await api("records");
  records = data.records;
  render();
  $("overview").replaceChildren();
  for (const [label, value] of Object.entries(data.metrics)) {
    const card = element("div", undefined, "metric");
    card.append(element("span", label), element("strong", value));
    $("overview").append(card);
  }
  $("events").replaceChildren();
  for (const event of data.events) {
    const li = element("li");
    li.append(
      element("time", new Date(event.at).toLocaleString()),
      element("span", event.message),
    );
    $("events").append(li);
  }
}
async function run(fn) {
  $("notice").textContent = "";
  try {
    await fn();
    await refresh();
  } catch (error) {
    $("notice").textContent = error.message;
  }
}
$("search").oninput = render;
$("record-form").onsubmit = async (e) => {
  e.preventDefault();
  const value = Object.fromEntries(new FormData(e.target));
  const button = e.target.querySelector("button");
  button.disabled = true;
  try {
    await api("records" + (editing ? "/" + editing : ""), {
      method: editing ? "PUT" : "POST",
      body: JSON.stringify(value),
    });
    reset();
    await refresh();
    $("notice").textContent = "Record saved.";
  } catch (error) {
    $("notice").textContent = error.message;
  } finally {
    button.disabled = false;
  }
};
$("export").onclick = () => {
  const quote = (v) => '"' + String(v ?? "").replaceAll('"', '""') + '"';
  const safe = (v) => (/^[=+\-@\t\r]/.test(String(v)) ? "'" + v : v);
  const columns = config.fields.map((f) => f.key);
  const csv = [columns, ...records.map((r) => columns.map((c) => safe(r[c])))]
    .map((r) => r.map(quote).join(","))
    .join("\r\n");
  const url = URL.createObjectURL(
    new Blob([csv], { type: "text/csv;charset=utf-8" }),
  );
  const a = element("a");
  a.href = url;
  a.download = config.slug + ".csv";
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
};
(async () => {
  try {
    config = await api("config");
    document.title = config.name + " · KYBER";
    $("title").textContent = config.name;
    $("description").textContent = config.description;
    $("eyebrow").textContent = config.category;
    buildForm();
    await refresh();
  } catch (error) {
    $("notice").textContent = error.message;
  }
})();

$('demo').onclick=async()=>{if(records.length){$('notice').textContent='Demo loading is available only in an empty workspace.';return;}const button=$('demo');button.disabled=true;await run(async()=>{for(const row of config.demo||[])await api('records',{method:'POST',body:JSON.stringify(row)});});button.disabled=false;};
