'use client';
import { useId, useRef, useState } from 'react';
import { formatBytes } from '../lib/format';
import { FileIcon } from './icons';

export function Dropzone({ file, onFile }: { file: File | null; onFile: (f: File | null) => void }) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [active, setActive] = useState(false);
  const id = useId();

  function pick(files: FileList | null) {
    if (files && files.length > 0) onFile(files[0]);
  }

  return (
    <div
      role="button"
      tabIndex={0}
      aria-labelledby={`${id}-label`}
      onClick={() => inputRef.current?.click()}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') inputRef.current?.click();
      }}
      onDragOver={(e) => {
        e.preventDefault();
        setActive(true);
      }}
      onDragLeave={() => setActive(false)}
      onDrop={(e) => {
        e.preventDefault();
        setActive(false);
        pick(e.dataTransfer.files);
      }}
      className={`cursor-pointer rounded-xl border-2 border-dashed p-6 text-center transition-colors ${active ? 'pwf-drop-active border-emerald-400' : 'border-edge bg-panel hover:border-mist/40'}`}
    >
      <input
        id={`${id}-label`}
        ref={inputRef}
        type="file"
        className="sr-only"
        onChange={(e) => {
          pick(e.target.files);
          e.target.value = '';
        }}
      />
      {file ? (
        <span className="flex items-center justify-center gap-3">
          <FileIcon mime={file.type || 'application/octet-stream'} />
          <span className="text-left">
            <span className="block max-w-64 truncate text-sm font-medium">{file.name}</span>
            <span className="block text-xs text-mist">{formatBytes(file.size)}</span>
          </span>
          <button
            aria-label="Remove file"
            className="rounded-full border border-edge px-2 py-0.5 text-xs text-mist hover:text-red-300"
            onClick={(e) => {
              e.stopPropagation();
              onFile(null);
            }}
          >
            ✕
          </button>
        </span>
      ) : (
        <span>
          <span className="block text-sm font-medium">Drop a file here, or click to browse</span>
          <span className="mt-1 block text-xs text-mist">Up to 5 GB · encrypted in transit · never indexed</span>
        </span>
      )}
    </div>
  );
}
