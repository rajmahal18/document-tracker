import { useEffect, useRef, useState } from 'react'

type Options = {
  enabled?: boolean
  rootMargin?: string
  threshold?: number
}

export function useLazyMount<T extends HTMLElement>({ enabled = true, rootMargin = '220px 0px 220px 0px', threshold = 0.01 }: Options = {}) {
  const ref = useRef<T | null>(null)
  const [isMounted, setIsMounted] = useState(!enabled)

  useEffect(() => {
    if (!enabled) {
      setIsMounted(true)
      return
    }

    const node = ref.current
    if (!node) return

    if (typeof window === 'undefined' || typeof window.IntersectionObserver === 'undefined') {
      setIsMounted(true)
      return
    }

    const observer = new window.IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) {
          setIsMounted(true)
          observer.disconnect()
        }
      },
      { rootMargin, threshold },
    )

    observer.observe(node)
    return () => observer.disconnect()
  }, [enabled, rootMargin, threshold])

  return {
    ref,
    isMounted,
  }
}
