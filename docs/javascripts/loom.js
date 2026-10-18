// Tint outcome markers: example comments (`// → …` recorded, `// ✗ …` not)
// and legend inline code starting with → or ✗. Pygments renders comments as
// plain spans, so CSS alone can't tell them apart.
const markOutcomes = () => {
  document.querySelectorAll(".md-typeset .highlight .c1, .md-typeset :not(pre) > code").forEach((node) => {
    const marker = node.textContent.match(/^(?:\/\/\s*)?([→✗])/u);
    if (marker) node.classList.add("loom-marker", marker[1] === "→" ? "loom-marker--hit" : "loom-marker--miss");
  });
};

if (window.document$) window.document$.subscribe(markOutcomes);
else document.addEventListener("DOMContentLoaded", markOutcomes);
