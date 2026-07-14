document.addEventListener("DOMContentLoaded", () => {
  const quotes = [
    `"I have no idea what I'm doing." – Also me, five minutes ago`,
    `"It worked on my machine." – Famous last words`,
    `"Why is it glowing?" – An actual concern`,
    `"Not a bug, it's a feature." – Technically true`,
    `"Who needs documentation?" – Someone else, hopefully`,
    `"Move fast and break everything." – You, reading this site`,
    `"Will it work? Maybe. Will it explode? Also maybe."`,
    `"Proudly built with duct tape, sarcasm, and Stack Overflow"`,
    `"404: sanity not found"`,
    `"One does not simply deploy to production... and yet, here we are."`
  ];

  const quoteBox = document.getElementById("quote-box");
  let quoteIndex = Math.floor(Math.random() * quotes.length);

  function showQuote() {
    if (!quoteBox) return;
    quoteBox.classList.remove("show");
    window.setTimeout(() => {
      quoteBox.textContent = quotes[quoteIndex];
      quoteBox.classList.add("show");
      quoteIndex = (quoteIndex + 1) % quotes.length;
    }, 500);
  }

  function startMatrix() {
    const canvas = document.getElementById("matrix");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const letters = "01";
    const fontSize = 14;
    let drops = [];

    function resize() {
      canvas.width = window.innerWidth;
      canvas.height = window.innerHeight;
      drops = Array(Math.ceil(canvas.width / fontSize)).fill(1);
    }

    function draw() {
      ctx.fillStyle = "rgba(0, 0, 0, 0.05)";
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = "#00f0ff";
      ctx.font = `${fontSize}px monospace`;

      for (let i = 0; i < drops.length; i += 1) {
        const text = letters[Math.floor(Math.random() * letters.length)];
        ctx.fillText(text, i * fontSize, drops[i] * fontSize);
        if (drops[i] * fontSize > canvas.height && Math.random() > 0.975) drops[i] = 0;
        drops[i] += 1;
      }
    }

    resize();
    window.addEventListener("resize", resize);
    window.setInterval(draw, 35);
  }

  const toggleArchive = document.getElementById("toggle-archive");
  const archivedList = document.getElementById("archived-list");
  const toggleIcon = document.getElementById("archive-arrow");

  if (toggleArchive && archivedList && toggleIcon) {
    toggleArchive.addEventListener("click", () => {
      archivedList.classList.toggle("collapsed");
      toggleIcon.textContent = archivedList.classList.contains("collapsed") ? "▶" : "▼";
    });
  }

  window.setInterval(showQuote, 6000);
  showQuote();
  startMatrix();
});
