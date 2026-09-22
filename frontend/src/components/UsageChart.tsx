import { useId } from 'react';
import { formatNumber } from '@/utils/format';

interface UsagePoint {
  date: string;
  checks: number;
}

interface UsageChartProps {
  data: UsagePoint[];
  height?: number;
}

/**
 * Daily check volume as an area chart.
 *
 * Drawn as inline SVG rather than pulling in a charting library for one figure.
 * The visual is marked decorative and the same numbers are given as a table for
 * screen readers, so nothing depends on seeing the shape.
 */
export function UsageChart({ data, height = 140 }: UsageChartProps) {
  const gradientId = useId();

  if (data.length === 0) {
    return null;
  }

  const width = 640;
  const paddingY = 8;
  const max = Math.max(1, ...data.map((point) => point.checks));
  const step = data.length > 1 ? width / (data.length - 1) : width;

  const points = data.map((point, index) => {
    const x = index * step;
    const y = height - paddingY - (point.checks / max) * (height - paddingY * 2);
    return { x, y, ...point };
  });

  const line = points.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ');
  const area = `${line} L${width},${height} L0,${height} Z`;

  const total = data.reduce((sum, point) => sum + point.checks, 0);

  if (total === 0) {
    return (
      <p className="ac-muted" style={{ fontSize: 'var(--ac-text-sm)' }}>
        No checks in the last {data.length} days. Your usage will appear here once a job runs.
      </p>
    );
  }

  return (
    <figure style={{ margin: 0 }}>
      <svg
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="none"
        style={{ width: '100%', height, display: 'block' }}
        aria-hidden="true"
        focusable="false"
      >
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="var(--ac-indigo-500)" stopOpacity="0.28" />
            <stop offset="100%" stopColor="var(--ac-indigo-500)" stopOpacity="0" />
          </linearGradient>
        </defs>
        <path d={area} fill={`url(#${gradientId})`} />
        <path
          d={line}
          fill="none"
          stroke="var(--ac-indigo-500)"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          vectorEffect="non-scaling-stroke"
        />
      </svg>

      <figcaption className="ac-sr-only">
        Checks per day over the last {data.length} days, {formatNumber(total)} in total.
        {points.map((point) => ` ${point.date}: ${point.checks}.`)}
      </figcaption>

      <div
        className="ac-row ac-row--between"
        style={{ marginTop: 'var(--ac-space-2)', fontSize: 'var(--ac-text-xs)', color: 'var(--ac-text-muted)' }}
      >
        <span>{data[0]?.date}</span>
        <span>
          {formatNumber(total)} checks · peak {formatNumber(max)}/day
        </span>
        <span>{data[data.length - 1]?.date}</span>
      </div>
    </figure>
  );
}
