export default function FieldError({ name, errors, prefix }) {
  if (!errors[name]) return null
  return <span id={`${prefix}-${name}-error`} className="field-error" role="alert">{[].concat(errors[name]).join(' ')}</span>
}
