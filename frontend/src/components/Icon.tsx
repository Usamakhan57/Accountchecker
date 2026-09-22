import type { SVGProps } from 'react';

/**
 * Original 24px stroke icon set.
 *
 * Icons are drawn on a 24-unit grid with a 1.7 stroke so they sit evenly
 * against 13-15px label text. They are decorative by default (aria-hidden);
 * pass a `title` when an icon is the only label for a control.
 */

export type IconName =
  | 'dashboard'
  | 'checkers'
  | 'mail'
  | 'platform'
  | 'duplicate'
  | 'sparkle'
  | 'jobs'
  | 'results'
  | 'history'
  | 'wallet'
  | 'pricing'
  | 'settings'
  | 'user'
  | 'admin'
  | 'users'
  | 'logs'
  | 'support'
  | 'bell'
  | 'menu'
  | 'close'
  | 'search'
  | 'download'
  | 'upload'
  | 'copy'
  | 'refresh'
  | 'play'
  | 'stop'
  | 'trash'
  | 'chevronLeft'
  | 'chevronRight'
  | 'check'
  | 'alert'
  | 'info'
  | 'logout'
  | 'plus'
  | 'external';

const PATHS: Record<IconName, JSX.Element> = {
  dashboard: (
    <>
      <rect x="3" y="3" width="7.5" height="8.5" rx="2" />
      <rect x="13.5" y="3" width="7.5" height="5" rx="2" />
      <rect x="13.5" y="11" width="7.5" height="10" rx="2" />
      <rect x="3" y="14.5" width="7.5" height="6.5" rx="2" />
    </>
  ),
  checkers: (
    <>
      <path d="M4 7h10" />
      <path d="M4 12h6" />
      <path d="M4 17h8" />
      <path d="m15.5 15.5 2 2 4-4.5" />
    </>
  ),
  mail: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2.5" />
      <path d="m3.8 7.4 7.1 5.1a2 2 0 0 0 2.2 0l7.1-5.1" />
    </>
  ),
  platform: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M3.2 9.8h17.6M3.2 14.2h17.6" />
      <path d="M12 3c2.4 2.6 3.6 5.6 3.6 9s-1.2 6.4-3.6 9c-2.4-2.6-3.6-5.6-3.6-9S9.6 5.6 12 3Z" />
    </>
  ),
  duplicate: (
    <>
      <rect x="3.5" y="3.5" width="12" height="12" rx="2.5" />
      <path d="M8.5 20.5h9a3 3 0 0 0 3-3v-9" />
    </>
  ),
  sparkle: (
    <>
      <path d="M12 3.5 13.7 9l5.5 1.7-5.5 1.7L12 18l-1.7-5.6L4.8 10.7 10.3 9 12 3.5Z" />
      <path d="M18.5 16.5 19.2 19l2.5.8-2.5.8-.7 2.4" />
    </>
  ),
  jobs: (
    <>
      <rect x="3" y="6" width="18" height="14" rx="2.5" />
      <path d="M8.5 6V4.6A1.6 1.6 0 0 1 10.1 3h3.8a1.6 1.6 0 0 1 1.6 1.6V6" />
      <path d="M3 11.5h18" />
    </>
  ),
  results: (
    <>
      <rect x="3" y="3.5" width="18" height="17" rx="2.5" />
      <path d="M3 9h18M9 9v11.5" />
    </>
  ),
  history: (
    <>
      <path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1" />
      <path d="M3.2 4v4.2h4.2" />
      <path d="M12 7.8V12l2.8 1.8" />
    </>
  ),
  wallet: (
    <>
      <path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h11.5A2.5 2.5 0 0 1 20 7.5v10A2.5 2.5 0 0 1 17.5 20H6a2.5 2.5 0 0 1-2.5-2.5Z" />
      <path d="M3.5 9.5h17" />
      <circle cx="16.5" cy="14" r="1.2" />
    </>
  ),
  pricing: (
    <>
      <path d="M4 9.5 11 3l9 4.5v9L11 21l-7-4.5Z" />
      <path d="M11 3v18" />
    </>
  ),
  settings: (
    <>
      <circle cx="12" cy="12" r="3.2" />
      <path d="M12 2.8v2.4M12 18.8v2.4M4.5 7.8l2 1.2M17.5 15l2 1.2M4.5 16.2l2-1.2M17.5 9l2-1.2" />
    </>
  ),
  user: (
    <>
      <circle cx="12" cy="8.2" r="3.7" />
      <path d="M4.8 20.2a7.2 7.2 0 0 1 14.4 0" />
    </>
  ),
  admin: (
    <>
      <path d="M12 3.2 19.5 6v6.1c0 4-3 7.5-7.5 8.7-4.5-1.2-7.5-4.7-7.5-8.7V6Z" />
      <path d="m9 12.2 2.2 2.2 4-4.3" />
    </>
  ),
  users: (
    <>
      <circle cx="9.5" cy="8.5" r="3.3" />
      <path d="M3.5 19.6a6 6 0 0 1 12 0" />
      <path d="M16 5.6a3.3 3.3 0 0 1 0 6.3M17.8 14.6a6 6 0 0 1 2.7 5" />
    </>
  ),
  logs: (
    <>
      <path d="M6 3.5h9.5L19 7v13.5H6Z" />
      <path d="M15 3.5V7h4" />
      <path d="M9 12h7M9 15.5h7" />
    </>
  ),
  support: (
    <>
      <path d="M4 11.5a8 8 0 0 1 16 0v4.9A2.6 2.6 0 0 1 17.4 19H14" />
      <rect x="2.8" y="11" width="3.8" height="5.6" rx="1.6" />
      <rect x="17.4" y="11" width="3.8" height="5.6" rx="1.6" />
    </>
  ),
  bell: (
    <>
      <path d="M6.5 10a5.5 5.5 0 0 1 11 0v4l1.6 2.8H4.9L6.5 14Z" />
      <path d="M10.2 20a2 2 0 0 0 3.6 0" />
    </>
  ),
  menu: <path d="M4 7h16M4 12h16M4 17h16" />,
  close: <path d="m6 6 12 12M18 6 6 18" />,
  search: (
    <>
      <circle cx="10.8" cy="10.8" r="6.3" />
      <path d="m15.4 15.4 4.3 4.3" />
    </>
  ),
  download: (
    <>
      <path d="M12 3.5v11" />
      <path d="m7.8 10.6 4.2 4.2 4.2-4.2" />
      <path d="M4.5 18.5v1a1.5 1.5 0 0 0 1.5 1.5h12a1.5 1.5 0 0 0 1.5-1.5v-1" />
    </>
  ),
  upload: (
    <>
      <path d="M12 20.5v-11" />
      <path d="m7.8 13.4 4.2-4.2 4.2 4.2" />
      <path d="M4.5 6.5v-1A1.5 1.5 0 0 1 6 4h12a1.5 1.5 0 0 1 1.5 1.5v1" />
    </>
  ),
  copy: (
    <>
      <rect x="8.5" y="8.5" width="12" height="12" rx="2.5" />
      <path d="M15.5 5.5A2 2 0 0 0 13.5 3.5H6A2.5 2.5 0 0 0 3.5 6v7.5a2 2 0 0 0 2 2" />
    </>
  ),
  refresh: (
    <>
      <path d="M20 12a8 8 0 1 1-2.4-5.7" />
      <path d="M20.5 3.5v4.2h-4.2" />
    </>
  ),
  play: <path d="M8 5.5v13l10.5-6.5Z" />,
  stop: <rect x="6.5" y="6.5" width="11" height="11" rx="2" />,
  trash: (
    <>
      <path d="M4.5 7h15" />
      <path d="M9.5 7V5.4A1.4 1.4 0 0 1 10.9 4h2.2a1.4 1.4 0 0 1 1.4 1.4V7" />
      <path d="M6.5 7.5 7.4 19a1.6 1.6 0 0 0 1.6 1.5h6a1.6 1.6 0 0 0 1.6-1.5L17.5 7.5" />
    </>
  ),
  chevronLeft: <path d="m14.5 5.5-7 6.5 7 6.5" />,
  chevronRight: <path d="m9.5 5.5 7 6.5-7 6.5" />,
  check: <path d="m5 12.6 4.6 4.6L19 6.8" />,
  alert: (
    <>
      <path d="M12 4.2 21 19.4H3Z" />
      <path d="M12 10v4.2M12 17.1h.01" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="8.6" />
      <path d="M12 11.2v5M12 8.1h.01" />
    </>
  ),
  logout: (
    <>
      <path d="M14.5 4.5h3A2 2 0 0 1 19.5 6.5v11a2 2 0 0 1-2 2h-3" />
      <path d="M11 12H3.8" />
      <path d="m7.4 8.4-3.6 3.6 3.6 3.6" />
    </>
  ),
  plus: <path d="M12 5v14M5 12h14" />,
  external: (
    <>
      <path d="M14 4.5h5.5V10" />
      <path d="M19 5 11.5 12.5" />
      <path d="M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10" />
    </>
  ),
};

interface IconProps extends Omit<SVGProps<SVGSVGElement>, 'name'> {
  name: IconName;
  size?: number;
  /** Accessible name. Omit for decorative icons next to visible text. */
  title?: string;
}

export function Icon({ name, size = 18, title, className, ...rest }: IconProps) {
  const isDecorative = title === undefined;

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.7}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      role={isDecorative ? undefined : 'img'}
      aria-hidden={isDecorative || undefined}
      focusable="false"
      {...rest}
    >
      {title ? <title>{title}</title> : null}
      {PATHS[name]}
    </svg>
  );
}
