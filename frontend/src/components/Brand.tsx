interface BrandMarkProps {
  size?: number;
  className?: string;
}

/**
 * The AccountCheck mark: a rounded indigo-to-cyan tile with a confirmation
 * stroke and a trailing node, suggesting "checked, and recorded".
 */
export function BrandMark({ size = 28, className }: BrandMarkProps) {
  const gradientId = `ac-brand-${size}`;

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      className={className}
      role="img"
      aria-label="AccountCheck"
      focusable="false"
    >
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="#4F5BF0" />
          <stop offset="1" stopColor="#22B8CF" />
        </linearGradient>
      </defs>
      <rect x="1" y="1" width="30" height="30" rx="9" fill={`url(#${gradientId})`} />
      <path
        d="M9 16.4 13.6 21 23 11.6"
        fill="none"
        stroke="#FFFFFF"
        strokeWidth={2.8}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx="23" cy="11.6" r="1.6" fill="#FFFFFF" opacity={0.55} />
    </svg>
  );
}

interface BrandProps {
  size?: number;
  className?: string;
  nameClassName?: string;
}

export function Brand({ size = 28, className, nameClassName }: BrandProps) {
  return (
    <span className={className} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.625rem' }}>
      <BrandMark size={size} />
      <span className={nameClassName}>AccountCheck</span>
    </span>
  );
}
