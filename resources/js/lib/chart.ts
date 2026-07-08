export interface ChartPoint {
    x: number;
    y: number;
}

/** Map raw values onto SVG coordinates (top-left origin), padded on all sides. */
export function chartPoints(values: number[], width: number, height: number, pad = 3): ChartPoint[] {
    if (values.length === 0) return [];
    if (values.length === 1) return [{ x: width / 2, y: height / 2 }];
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;
    return values.map((v, i) => ({
        x: pad + (i / (values.length - 1)) * (width - pad * 2),
        y: pad + (1 - (v - min) / range) * (height - pad * 2),
    }));
}

/** Smooth Catmull-Rom spline through the points, as an SVG path. */
export function linePath(pts: ChartPoint[]): string {
    if (pts.length < 2) return '';
    let d = `M ${pts[0].x.toFixed(2)} ${pts[0].y.toFixed(2)}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[Math.max(0, i - 1)];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[Math.min(pts.length - 1, i + 2)];
        const c1x = p1.x + (p2.x - p0.x) / 6;
        const c1y = p1.y + (p2.y - p0.y) / 6;
        const c2x = p2.x - (p3.x - p1.x) / 6;
        const c2y = p2.y - (p3.y - p1.y) / 6;
        d += ` C ${c1x.toFixed(2)} ${c1y.toFixed(2)}, ${c2x.toFixed(2)} ${c2y.toFixed(2)}, ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`;
    }
    return d;
}

/** Close a line path down to the bottom edge so it can be filled as an area. */
export function areaPath(line: string, width: number, height: number, pad = 3): string {
    if (!line) return '';
    return `${line} L ${(width - pad).toFixed(2)} ${height} L ${pad} ${height} Z`;
}
