import { useId } from 'react';
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';

/**
 * Form controls with an always-associated label, a described hint and an
 * aria-live error message, so keyboard and screen-reader users get the same
 * feedback sighted users do.
 */

interface BaseFieldProps {
  label: string;
  hint?: ReactNode;
  error?: string;
  required?: boolean;
}

function FieldShell({
  label,
  hint,
  error,
  required,
  controlId,
  hintId,
  errorId,
  children,
}: BaseFieldProps & {
  controlId: string;
  hintId: string;
  errorId: string;
  children: ReactNode;
}) {
  return (
    <div className="ac-field">
      <label className="ac-field__label" htmlFor={controlId}>
        {label}
        {required && (
          <span className="ac-field__required" aria-hidden="true">
            *
          </span>
        )}
      </label>
      {children}
      {hint && (
        <span className="ac-field__hint" id={hintId}>
          {hint}
        </span>
      )}
      <span className="ac-field__error" id={errorId} role="alert">
        {error}
      </span>
    </div>
  );
}

type TextFieldProps = BaseFieldProps & InputHTMLAttributes<HTMLInputElement>;

export function TextField({ label, hint, error, required, id, ...rest }: TextFieldProps) {
  const generatedId = useId();
  const controlId = id ?? generatedId;
  const hintId = `${controlId}-hint`;
  const errorId = `${controlId}-error`;

  return (
    <FieldShell
      label={label}
      hint={hint}
      error={error}
      required={required}
      controlId={controlId}
      hintId={hintId}
      errorId={errorId}
    >
      <input
        {...rest}
        id={controlId}
        className="ac-input"
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={[hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined}
      />
    </FieldShell>
  );
}

type TextAreaFieldProps = BaseFieldProps & TextareaHTMLAttributes<HTMLTextAreaElement>;

export function TextAreaField({ label, hint, error, required, id, ...rest }: TextAreaFieldProps) {
  const generatedId = useId();
  const controlId = id ?? generatedId;
  const hintId = `${controlId}-hint`;
  const errorId = `${controlId}-error`;

  return (
    <FieldShell
      label={label}
      hint={hint}
      error={error}
      required={required}
      controlId={controlId}
      hintId={hintId}
      errorId={errorId}
    >
      <textarea
        {...rest}
        id={controlId}
        className="ac-textarea"
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={[hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined}
      />
    </FieldShell>
  );
}

type SelectFieldProps = BaseFieldProps &
  SelectHTMLAttributes<HTMLSelectElement> & {
    options: { value: string; label: string; disabled?: boolean }[];
  };

export function SelectField({ label, hint, error, required, id, options, ...rest }: SelectFieldProps) {
  const generatedId = useId();
  const controlId = id ?? generatedId;
  const hintId = `${controlId}-hint`;
  const errorId = `${controlId}-error`;

  return (
    <FieldShell
      label={label}
      hint={hint}
      error={error}
      required={required}
      controlId={controlId}
      hintId={hintId}
      errorId={errorId}
    >
      <select
        {...rest}
        id={controlId}
        className="ac-select"
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={[hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </option>
        ))}
      </select>
    </FieldShell>
  );
}
