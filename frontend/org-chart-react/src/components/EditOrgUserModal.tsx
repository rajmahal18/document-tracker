import { useEffect, useMemo, useRef, useState } from 'react'
import type { AssignableRolesMap, OrgUser, UpdateOrgUserPayload } from '../types/org'
import { parseAssistantCandidates, parseSelectedAssistantIds, userInitials } from '../lib/org-helpers'

type Props = {
  open: boolean
  user: OrgUser | null
  assignableRoles: AssignableRolesMap
  onClose: () => void
  onSave: (payload: UpdateOrgUserPayload) => Promise<void>
}

type PhotoCrop = {
  zoom: number
  x: number
  y: number
}

async function cropProfilePhoto(file: File, crop: PhotoCrop): Promise<File> {
  const imageUrl = URL.createObjectURL(file)
  try {
    const image = await new Promise<HTMLImageElement>((resolve, reject) => {
      const img = new Image()
      img.onload = () => resolve(img)
      img.onerror = () => reject(new Error('Could not read the selected photo.'))
      img.src = imageUrl
    })

    const targetWidth = 800
    const targetHeight = 800
    const canvas = document.createElement('canvas')
    canvas.width = targetWidth
    canvas.height = targetHeight
    const ctx = canvas.getContext('2d')
    if (!ctx) throw new Error('Could not prepare the photo crop.')

    const baseScale = Math.max(targetWidth / image.naturalWidth, targetHeight / image.naturalHeight)
    const scale = baseScale * crop.zoom
    const drawWidth = image.naturalWidth * scale
    const drawHeight = image.naturalHeight * scale
    const centerX = (targetWidth - drawWidth) / 2
    const centerY = (targetHeight - drawHeight) / 2

    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, targetWidth, targetHeight)
    ctx.drawImage(image, centerX + crop.x, centerY + crop.y, drawWidth, drawHeight)

    const blob = await new Promise<Blob>((resolve, reject) => {
      canvas.toBlob((result) => {
        if (result) resolve(result)
        else reject(new Error('Could not crop the selected photo.'))
      }, 'image/jpeg', 0.9)
    })

    const baseName = file.name.replace(/\.[^.]+$/, '') || 'profile-photo'
    return new File([blob], `${baseName}-id-crop.jpg`, { type: 'image/jpeg' })
  } finally {
    URL.revokeObjectURL(imageUrl)
  }
}

export function EditOrgUserModal({ open, user, assignableRoles, onClose, onSave }: Props) {
  const [fullName, setFullName] = useState('')
  const [email, setEmail] = useState('')
  const [officialTitle, setOfficialTitle] = useState('')
  const [authorityRole, setAuthorityRole] = useState('staff')
  const [permanent, setPermanent] = useState(true)
  const [assistantIds, setAssistantIds] = useState<number[]>([])
  const [profilePhoto, setProfilePhoto] = useState<File | null>(null)
  const [removeProfilePhoto, setRemoveProfilePhoto] = useState(false)
  const [profilePhotoPreview, setProfilePhotoPreview] = useState('')
  const [photoCrop, setPhotoCrop] = useState<PhotoCrop>({ zoom: 1.08, x: 0, y: 0 })
  const [cropDialogOpen, setCropDialogOpen] = useState(false)
  const dragRef = useRef<{ pointerId: number; startX: number; startY: number; cropX: number; cropY: number } | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState('')

  const candidates = useMemo(() => (user ? parseAssistantCandidates(user) : []), [user])
  const canEditBasic = Boolean(user?.can_edit)
  const canUploadPhoto = Boolean(user?.can_upload_photo)
  const canAssignAssistant = Boolean(user?.can_assign_assistant)

  useEffect(() => {
    if (!user) return
    setFullName(user.full_name || '')
    setEmail(user.email || '')
    setOfficialTitle(user.official_title || '')
    setAuthorityRole(user.authority_role || 'staff')
    setPermanent((user.permanent ?? 1) === 1)
    setAssistantIds(parseSelectedAssistantIds(user))
    setProfilePhoto(null)
    setRemoveProfilePhoto(false)
    setProfilePhotoPreview('')
    setPhotoCrop({ zoom: 1.08, x: 0, y: 0 })
    setCropDialogOpen(false)
    setError('')
  }, [user])

  useEffect(() => {
    if (!profilePhoto) {
      setProfilePhotoPreview('')
      return
    }
    const url = URL.createObjectURL(profilePhoto)
    setProfilePhotoPreview(url)
    return () => URL.revokeObjectURL(url)
  }, [profilePhoto])

  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open || !user) return null

  const savedProfilePhotoUrl = user.profile_photo_url || user.avatar_url || user.photo_url || ''
  const visibleProfilePhotoUrl = profilePhotoPreview || (!removeProfilePhoto ? savedProfilePhotoUrl : '')

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    setError('')
    setIsSaving(true)
    try {
      const croppedProfilePhoto = profilePhoto ? await cropProfilePhoto(profilePhoto, photoCrop) : null
      await onSave({
        target_user_id: user.id,
        full_name: fullName,
        email,
        official_title: officialTitle,
        authority_role: authorityRole,
        permanent,
        chief_assistant_user_ids: assistantIds,
        profile_photo: croppedProfilePhoto,
        remove_profile_photo: removeProfilePhoto,
      })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save changes.')
    } finally {
      setIsSaving(false)
    }
  }

  const startPhotoDrag = (event: React.PointerEvent<HTMLDivElement>) => {
    event.currentTarget.setPointerCapture(event.pointerId)
    dragRef.current = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      cropX: photoCrop.x,
      cropY: photoCrop.y,
    }
  }

  const movePhotoDrag = (event: React.PointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current
    if (!drag || drag.pointerId !== event.pointerId) return
    const rect = event.currentTarget.getBoundingClientRect()
    const maxPan = rect.width * 0.55
    const nextX = Math.max(-maxPan, Math.min(maxPan, drag.cropX + event.clientX - drag.startX))
    const nextY = Math.max(-maxPan, Math.min(maxPan, drag.cropY + event.clientY - drag.startY))
    setPhotoCrop((current) => ({ ...current, x: nextX, y: nextY }))
  }

  const endPhotoDrag = (event: React.PointerEvent<HTMLDivElement>) => {
    if (dragRef.current?.pointerId === event.pointerId) {
      dragRef.current = null
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/50 px-4 py-6 backdrop-blur-sm">
      <div className="org-shell w-full max-w-3xl overflow-hidden border-white/80">
        <div className="border-b border-ink-100 px-5 py-4">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-700">Edit org user</p>
              <h3 className="mt-1 text-lg font-semibold text-ink-950">{user.full_name}</h3>
              <p className="mt-1 text-sm text-ink-500">{user.division_name} · {user.section_name}</p>
            </div>
            <button type="button" className="btn-secondary" onClick={onClose} disabled={isSaving}>Close</button>
          </div>
        </div>

        <form className="space-y-5 px-5 py-5" onSubmit={submit}>
          {error ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-700">{error}</div>
          ) : null}

          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <label className="field-label">Full name</label>
              <input className="field-input" value={fullName} onChange={(event) => setFullName(event.target.value)} disabled={!canEditBasic} />
            </div>
            <div>
              <label className="field-label">Email</label>
              <input className="field-input" type="email" value={email} onChange={(event) => setEmail(event.target.value)} disabled={!canEditBasic} />
            </div>
            <div>
              <label className="field-label">Official title</label>
              <input className="field-input" value={officialTitle} onChange={(event) => setOfficialTitle(event.target.value)} disabled={!canEditBasic} />
            </div>
            <div>
              <label className="field-label">Authority role</label>
              <select className="field-select" value={authorityRole} onChange={(event) => setAuthorityRole(event.target.value)} disabled={!canEditBasic}>
                {Object.entries(assignableRoles).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_280px]">
            <div>
              {canAssignAssistant ? (
                <div>
                  <label className="field-label">Assigned assistants</label>
                  <select
                    className="field-select min-h-[180px]"
                    multiple
                    value={assistantIds.map(String)}
                    onChange={(event) => {
                      const values = Array.from(event.target.selectedOptions).map((option) => Number(option.value)).filter((id) => id > 0)
                      setAssistantIds(values)
                    }}
                  >
                    {candidates.map((candidate) => (
                      <option key={candidate.id} value={candidate.id}>
                        {candidate.full_name} {candidate.display_title ? `— ${candidate.display_title}` : ''}
                      </option>
                    ))}
                  </select>
                  <p className="mt-2 text-xs leading-5 text-ink-500">
                    Choose one or more staff users within the allowed scope. Leave all options unselected to clear assistant assignments.
                  </p>
                </div>
              ) : (
                <div className="rounded-[22px] border border-dashed border-ink-200 bg-ink-50 px-4 py-4 text-sm text-ink-500">
                  Assistant assignment is not available for this user.
                </div>
              )}
            </div>

            <div className="space-y-3">
              <div className="profile-photo-editor">
                <div className="profile-photo-preview">
                  {visibleProfilePhotoUrl ? (
                    <img
                      src={visibleProfilePhotoUrl}
                      alt=""
                      style={profilePhoto ? {
                        transform: `translate(${photoCrop.x * 0.16}px, ${photoCrop.y * 0.16}px) scale(${photoCrop.zoom})`,
                      } : undefined}
                    />
                  ) : (
                    <span>{userInitials(user.full_name)}</span>
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <label className="field-label">Profile photo</label>
                  <div className="flex flex-wrap gap-2">
                    <label className={`btn-secondary profile-photo-action ${!canUploadPhoto || isSaving ? 'is-disabled' : ''}`}>
                      Change pic
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        disabled={!canUploadPhoto || isSaving}
                        onChange={(event) => {
                          const file = event.target.files?.[0] ?? null
                          setProfilePhoto(file)
                          if (file) {
                            setRemoveProfilePhoto(false)
                            setPhotoCrop({ zoom: 1.08, x: 0, y: 0 })
                            setCropDialogOpen(true)
                          }
                        }}
                      />
                    </label>
                    {savedProfilePhotoUrl || profilePhoto ? (
                      <button
                        type="button"
                        className="btn-secondary profile-photo-action"
                        disabled={!canUploadPhoto || isSaving}
                        onClick={() => {
                          setProfilePhoto(null)
                          setRemoveProfilePhoto(Boolean(savedProfilePhotoUrl))
                          setCropDialogOpen(false)
                        }}
                      >
                        Remove
                      </button>
                    ) : null}
                  </div>
                  <p className="mt-2 truncate text-xs text-ink-500">
                    {profilePhoto ? profilePhoto.name : removeProfilePhoto ? 'Photo will be removed on save.' : 'JPG, PNG, or WebP up to 2MB.'}
                  </p>
                </div>
              </div>

              <label className="flex items-start gap-3 rounded-[22px] border border-ink-100 bg-ink-50 px-4 py-3.5 text-sm text-ink-700">
                <input type="checkbox" checked={permanent} onChange={(event) => setPermanent(event.target.checked)} disabled={!canEditBasic} className="mt-0.5" />
                <span>
                  <span className="block font-semibold text-ink-900">Permanent employee</span>
                  <span className="mt-1 block text-xs leading-5 text-ink-500">Keep this checked for plantilla or permanent personnel records.</span>
                </span>
              </label>

              <div className="rounded-[22px] border border-brand-100 bg-brand-50/70 px-4 py-3.5 text-xs leading-5 text-ink-600">
                Save only after reviewing titles, roles, and assistant assignments. Changes are sent directly to the existing PHP backend endpoint.
              </div>
            </div>
          </div>

          <div className="flex flex-col-reverse gap-3 border-t border-ink-100 pt-4 sm:flex-row sm:justify-end">
            <button type="button" className="btn-secondary" onClick={onClose} disabled={isSaving}>Cancel</button>
            <button type="submit" className="btn-primary" disabled={isSaving}>{isSaving ? 'Saving...' : 'Save changes'}</button>
          </div>
        </form>
      </div>
      {cropDialogOpen && profilePhoto && profilePhotoPreview ? (
        <div className="profile-crop-overlay" role="dialog" aria-modal="true" aria-label="Choose profile picture">
          <div className="profile-crop-dialog">
            <div className="profile-crop-dialog-header">
              <h4>Choose profile picture</h4>
              <button type="button" onClick={() => setCropDialogOpen(false)} aria-label="Close crop dialog">x</button>
            </div>
            <div className="profile-crop-description">Drag the photo until the face sits cleanly inside the square ID frame.</div>
            <div className="profile-crop-stage">
              <div
                className="profile-crop-large-frame"
                onPointerDown={startPhotoDrag}
                onPointerMove={movePhotoDrag}
                onPointerUp={endPhotoDrag}
                onPointerCancel={endPhotoDrag}
              >
                <img
                  src={profilePhotoPreview}
                  alt=""
                  draggable={false}
                  onDragStart={(event) => event.preventDefault()}
                  style={{
                    transform: `translate(${photoCrop.x}px, ${photoCrop.y}px) scale(${photoCrop.zoom})`,
                  }}
                />
                <div className="profile-crop-shade" aria-hidden="true" />
              </div>
            </div>
            <div className="profile-crop-fb-controls">
              <span>-</span>
              <input
                type="range"
                min="1"
                max="2"
                step="0.01"
                value={photoCrop.zoom}
                onChange={(event) => setPhotoCrop((current) => ({ ...current, zoom: Number(event.target.value) }))}
                aria-label="Zoom profile photo"
              />
              <span>+</span>
            </div>
            <div className="profile-crop-dialog-footer">
              <button type="button" className="btn-secondary" onClick={() => setCropDialogOpen(false)}>Done</button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
