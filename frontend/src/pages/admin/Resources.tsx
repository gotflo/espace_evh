import { AppLayout } from '../../components/AppLayout'
import { ResourcesManager } from '../../components/ResourcesManager'

/** Ressources et liens video (themes des RM) : page de gestion dediee. */
export default function Resources() {
  return (
    <AppLayout title="Ressources" subtitle="Liens et vidéos proposés aux membres">
      <ResourcesManager />
    </AppLayout>
  )
}
