import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createComponent,
  deleteComponent,
  getComponent,
  listComponents,
  updateComponent,
} from '@/api/endpoints/components';
import type { ComponentDto } from '@/api/types/component';
import { componentKeys } from '@/hooks/componentKeys';
import { maintenanceKeys } from '@/hooks/useMaintenances';
import { userValueKeys } from '@/hooks/userValueKeys';

export function useComponents(vehicleId: number) {
  return useQuery({
    queryKey: componentKeys.byVehicle(vehicleId),
    queryFn: () => listComponents(vehicleId),
    enabled: vehicleId > 0,
  });
}

export function useComponent(id: number) {
  return useQuery({
    queryKey: componentKeys.detail(id),
    queryFn: () => getComponent(id),
    enabled: id > 0,
  });
}

/** Il negozio entra nei suggerimenti; nome e collegamenti si vedono anche nei lavori. */
function useInvalidateAfterWrite() {
  const qc = useQueryClient();
  return () => {
    for (const key of [componentKeys.all, userValueKeys.all, maintenanceKeys.all]) {
      void qc.invalidateQueries({ queryKey: key });
    }
  };
}

export function useCreateComponent() {
  const invalidate = useInvalidateAfterWrite();
  return useMutation({ mutationFn: (body: ComponentDto) => createComponent(body), onSuccess: invalidate });
}

export function useUpdateComponent(id: number) {
  const invalidate = useInvalidateAfterWrite();
  return useMutation({ mutationFn: (body: ComponentDto) => updateComponent(id, body), onSuccess: invalidate });
}

export function useDeleteComponent() {
  const invalidate = useInvalidateAfterWrite();
  return useMutation({ mutationFn: (id: number) => deleteComponent(id), onSuccess: invalidate });
}
