import type { ReactNode } from 'react';

interface CardProps {
  title?: ReactNode;
  subtitle?: ReactNode;
  actions?: ReactNode;
  footer?: ReactNode;
  children: ReactNode;
  /** Removes body padding, for a table that should run edge to edge. */
  flush?: boolean;
  className?: string;
  id?: string;
}

export function Card({ title, subtitle, actions, footer, children, flush, className, id }: CardProps) {
  return (
    <section className={['ac-card', className].filter(Boolean).join(' ')} id={id}>
      {(title || actions) && (
        <header className="ac-card__header">
          <div>
            {title && <h2 className="ac-card__title">{title}</h2>}
            {subtitle && <p className="ac-card__subtitle">{subtitle}</p>}
          </div>
          {actions && <div className="ac-row">{actions}</div>}
        </header>
      )}
      <div className={flush ? 'ac-card__body ac-card__body--flush' : 'ac-card__body'}>{children}</div>
      {footer && <footer className="ac-card__footer">{footer}</footer>}
    </section>
  );
}
