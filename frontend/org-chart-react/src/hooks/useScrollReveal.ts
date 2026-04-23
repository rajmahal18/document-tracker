import { useEffect, useRef, useState } from 'react'

type Options = {
  delayMs?: number
  rootMargin?: string
  threshold?: number
}

export function useScrollReveal({ delayMs = 0, rootMargin = '0px 0px -10% 0px', threshold = 0.14 }: Options = {}) {
  const ref = useRef<HTMLElement | null>(null)
  const [isVisible, setIsVisible] = useState(false)

  useEffect(() => {
    const node = ref.current
    if (!node) return

    if (typeof window === 'undefined') {
      setIsVisible(true)
      return
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || typeof window.IntersectionObserver === 'undefined') {
      setIsVisible(true)
      return
    }

    const observer = new window.IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) {
          setIsVisible(true)
          observer.disconnect()
        }
      },
      { rootMargin, threshold },
    )

    observer.observe(node)
    return () => observer.disconnect()
  }, [rootMargin, threshold])

  return {
    ref,
    isVisible,
    delayMs,
  }
}
