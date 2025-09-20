import React from 'react';
import { useFormik } from 'formik';
import * as yup from 'yup';

const httpDateRegex = /^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/;

const validationSchema = yup.object({
  maxAge: yup.number().min(0).max(31536000).nullable(),
  expires: yup.string().matches(httpDateRegex, 'Must be RFC 1123 date').nullable(),
  lastModifiedMode: yup.string().oneOf(['static', 'date', 'file']).nullable(),
  lastModifiedStatic: yup.string().when('lastModifiedMode', {
    is: 'static',
    then: yup.string().matches(httpDateRegex, 'Must be RFC 1123 date').required(),
    otherwise: yup.string().nullable(),
  }),
  vary: yup.string().max(128).nullable(),
  pragma: yup.string().max(128).nullable(),
});

export default function CacheHeaderForm({ initialValues, onSubmit }) {
  const formik = useFormik({
    initialValues,
    validationSchema,
    onSubmit,
  });

  return (
    <form onSubmit={formik.handleSubmit}>
      <label>
        Max-Age (seconds):
        <input
          type="number"
          name="maxAge"
          value={formik.values.maxAge || ''}
          onChange={formik.handleChange}
        />
        {formik.errors.maxAge && <div style={{color:'red'}}>{formik.errors.maxAge}</div>}
      </label>
      <br />
      <label>
        Expires (RFC 1123 date):
        <input
          type="text"
          name="expires"
          value={formik.values.expires || ''}
          onChange={formik.handleChange}
        />
        {formik.errors.expires && <div style={{color:'red'}}>{formik.errors.expires}</div>}
      </label>
      <br />
      <label>
        Last-Modified Mode:
        <select
          name="lastModifiedMode"
          value={formik.values.lastModifiedMode || ''}
          onChange={formik.handleChange}
        >
          <option value="">Select</option>
          <option value="static">Static</option>
          <option value="date">Date</option>
          <option value="file">File</option>
        </select>
        {formik.errors.lastModifiedMode && <div style={{color:'red'}}>{formik.errors.lastModifiedMode}</div>}
      </label>
      <br />
      {formik.values.lastModifiedMode === 'static' && (
        <label>
          Last-Modified Static (RFC 1123 date):
          <input
            type="text"
            name="lastModifiedStatic"
            value={formik.values.lastModifiedStatic || ''}
            onChange={formik.handleChange}
          />
          {formik.errors.lastModifiedStatic && <div style={{color:'red'}}>{formik.errors.lastModifiedStatic}</div>}
        </label>
      )}
      <br />
      <label>
        Vary:
        <input
          type="text"
          name="vary"
          value={formik.values.vary || ''}
          onChange={formik.handleChange}
        />
        {formik.errors.vary && <div style={{color:'red'}}>{formik.errors.vary}</div>}
      </label>
      <br />
      <label>
        Pragma:
        <input
          type="text"
          name="pragma"
          value={formik.values.pragma || ''}
          onChange={formik.handleChange}
        />
        {formik.errors.pragma && <div style={{color:'red'}}>{formik.errors.pragma}</div>}
      </label>
      <br />
      <button type="submit">Save</button>
    </form>
  );
}
