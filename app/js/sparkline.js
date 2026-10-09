/**
 * Мини-график на canvas для <div r-js="sparkline" r-props="{points: points}">.
 * mount() получает элемент, свойства из шаблона и ctx; возвращает update() и unmount().
 */
export function mount(el, props, ctx) {
  const canvas = document.createElement('canvas');
  canvas.style.cssText = 'width:100%;height:100%;display:block;cursor:pointer';
  el.replaceChildren(canvas);
  let points = props.points || [];

  function draw() {
    const dpr = window.devicePixelRatio || 1;
    const w = el.clientWidth, h = el.clientHeight;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    const g = canvas.getContext('2d');
    g.scale(dpr, dpr);
    g.clearRect(0, 0, w, h);
    if (points.length < 2) return;
    const max = Math.max(...points), min = Math.min(...points);
    const x = (i) => 12 + (i * (w - 24)) / (points.length - 1);
    const y = (v) => h - 12 - ((v - min) / (max - min || 1)) * (h - 24);
    g.lineWidth = 2;
    g.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--accent') || '#2f5bd3';
    g.beginPath();
    points.forEach((v, i) => (i ? g.lineTo(x(i), y(v)) : g.moveTo(x(i), y(v))));
    g.stroke();
    g.fillStyle = g.strokeStyle;
    points.forEach((v, i) => {
      g.beginPath();
      g.arc(x(i), y(v), 3, 0, Math.PI * 2);
      g.fill();
    });
    canvas.onclick = (e) => {
      const r = canvas.getBoundingClientRect();
      const i = Math.round(((e.clientX - r.left - 12) / (w - 24)) * (points.length - 1));
      if (points[i] !== undefined) ctx.emit('pick', points[i]);
    };
  }

  const ro = new ResizeObserver(draw);
  ro.observe(el);
  draw();

  return {
    update(next) {
      points = next.points || [];
      draw();
    },
    unmount() {
      ro.disconnect();
    },
  };
}
